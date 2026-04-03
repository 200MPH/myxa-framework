<?php

declare(strict_types=1);

namespace Myxa\Storage\Db;

use InvalidArgumentException;
use JsonException;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Query\QueryBuilder;
use Myxa\Storage\AbstractStorage;
use Myxa\Storage\StorageException;
use Myxa\Storage\StoredFile;

final class DatabaseStorage extends AbstractStorage
{
    private ?DatabaseManager $resolvedManager = null;

    /**
     * @param string $fileTable Metadata table name.
     * @param string $contentTable Content table name.
     * @param DatabaseManager|null $manager Optional prebuilt database manager.
     * @param string $alias Public storage alias.
     */
    public function __construct(
        private readonly string $fileTable = 'files',
        private readonly string $contentTable = 'file_contents',
        ?DatabaseManager $manager = null,
        string $alias = 'db',
    ) {
        parent::__construct($alias);

        $this->assertIdentifier($this->fileTable);
        $this->assertIdentifier($this->contentTable);
        $this->resolvedManager = $manager;
    }

    /**
     * Persist contents into database-backed storage.
     *
     * @param array{name?: string, mime_type?: string, metadata?: array<string, mixed>} $options
     */
    public function put(string $location, string $contents, array $options = []): StoredFile
    {
        $location = $this->normalizeLocation($location);
        $size = strlen($contents);
        $name = $this->resolveName($location, $options);
        $mimeType = $this->resolveMimeType($options);
        $checksum = sha1($contents);
        $metadata = $this->resolveMetadata($options);
        $encodedMetadata = $this->encodeMetadata($metadata);
        $timestamp = date('c');
        $existing = $this->rowFor($location);

        $this->manager()->transaction(function () use (
            $existing,
            $location,
            $name,
            $mimeType,
            $size,
            $checksum,
            $encodedMetadata,
            $timestamp,
            $contents,
        ): void {
            if ($existing === null) {
                $id = $this->insert(
                    $this->manager()
                        ->query()
                        ->insertInto($this->fileTable)
                        ->values([
                            'location' => $location,
                            'filename' => $name,
                            'mime_type' => $mimeType,
                            'size' => $size,
                            'checksum' => $checksum,
                            'metadata' => $encodedMetadata,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]),
                );

                $this->insert(
                    $this->manager()
                        ->query()
                        ->insertInto($this->contentTable)
                        ->values([
                            'file_id' => $id,
                            'content' => $contents,
                        ]),
                );

                return;
            }

            $this->update(
                $this->manager()
                    ->query()
                    ->update($this->fileTable)
                    ->setMany([
                        'filename' => $name,
                        'mime_type' => $mimeType,
                        'size' => $size,
                        'checksum' => $checksum,
                        'metadata' => $encodedMetadata,
                        'updated_at' => $timestamp,
                    ])
                    ->where('id', '=', (int) $existing['id']),
            );

            $this->update(
                $this->manager()
                    ->query()
                    ->update($this->contentTable)
                    ->set('content', $contents)
                    ->where('file_id', '=', (int) $existing['id']),
            );
        });

        return $this->get($location) ?? throw new StorageException(sprintf('Unable to persist file "%s".', $location));
    }

    /**
     * Return file metadata for a database-backed location.
     */
    public function get(string $location): ?StoredFile
    {
        $location = $this->normalizeLocation($location);
        $row = $this->rowFor($location);

        if ($row === null) {
            return null;
        }

        return $this->storedFileFromRow($row);
    }

    /**
     * Read raw contents from database-backed storage.
     */
    public function read(string $location): string
    {
        $location = $this->normalizeLocation($location);
        $row = $this->rowFor($location);

        if ($row === null) {
            throw new StorageException(sprintf('File "%s" does not exist in "%s" storage.', $location, $this->alias()));
        }

        $content = $this->contentFor((int) $row['id']);

        if ($content === null) {
            throw new StorageException(sprintf('File "%s" does not exist in "%s" storage.', $location, $this->alias()));
        }

        return $content;
    }

    /**
     * Delete a file from database-backed storage.
     */
    public function delete(string $location): bool
    {
        $location = $this->normalizeLocation($location);
        $row = $this->rowFor($location);

        if ($row === null) {
            return false;
        }

        $this->manager()->transaction(function () use ($row): void {
            $id = (int) $row['id'];

            $this->deleteQuery(
                $this->manager()
                    ->query()
                    ->deleteFrom($this->contentTable)
                    ->where('file_id', '=', $id),
            );

            $this->deleteQuery(
                $this->manager()
                    ->query()
                    ->deleteFrom($this->fileTable)
                    ->where('id', '=', $id),
            );
        });

        return true;
    }

    /**
     * Determine whether a file exists in database-backed storage.
     */
    public function exists(string $location): bool
    {
        return $this->rowFor($this->normalizeLocation($location)) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowFor(string $location): ?array
    {
        $rows = $this->select(
            $this->manager()
                ->query()
                ->select(
                    'id',
                    'location',
                    'filename',
                    'mime_type',
                    'size',
                    'checksum',
                    'metadata',
                    'created_at',
                    'updated_at',
                )
                ->from($this->fileTable)
                ->where('location', '=', $location)
                ->limit(1),
        );

        return $rows[0] ?? null;
    }

    private function contentFor(int $fileId): ?string
    {
        $rows = $this->select(
            $this->manager()
                ->query()
                ->select('content')
                ->from($this->contentTable)
                ->where('file_id', '=', $fileId)
                ->limit(1),
        );

        if (!array_key_exists(0, $rows) || !array_key_exists('content', $rows[0])) {
            return null;
        }

        return (string) $rows[0]['content'];
    }

    private function insert(QueryBuilder $query): string|int
    {
        return $this->manager()->insert($query->toSql(), $query->getBindings());
    }

    private function update(QueryBuilder $query): int
    {
        return $this->manager()->update($query->toSql(), $query->getBindings());
    }

    private function deleteQuery(QueryBuilder $query): int
    {
        return $this->manager()->delete($query->toSql(), $query->getBindings());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function storedFileFromRow(array $row): StoredFile
    {
        $metadata = $this->decodeMetadata($row['metadata'] ?? null);
        $metadata['database_id'] = (int) $row['id'];
        $metadata['created_at'] = $row['created_at'] ?? null;
        $metadata['updated_at'] = $row['updated_at'] ?? null;

        return new StoredFile(
            $this->alias(),
            (string) $row['location'],
            (string) $row['filename'],
            (int) $row['size'],
            isset($row['mime_type']) ? (string) $row['mime_type'] : null,
            isset($row['checksum']) ? (string) $row['checksum'] : null,
            $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function encodeMetadata(array $metadata): string
    {
        try {
            return json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stored file metadata must be JSON serializable.', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (!is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        try {
            $decoded = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function manager(): DatabaseManager
    {
        return $this->resolvedManager ??= new DatabaseManager();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function select(QueryBuilder $query): array
    {
        return $this->manager()->select(
            $query->toSql(),
            $query->getBindings(),
        );
    }

    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $identifier));
        }
    }
}
