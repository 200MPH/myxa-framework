<?php

declare(strict_types=1);

namespace Myxa\Support\Storage\Db;

use InvalidArgumentException;
use JsonException;
use Myxa\Database\DatabaseManager;
use Myxa\Support\Storage\AbstractStorage;
use Myxa\Support\Storage\StorageException;
use Myxa\Support\Storage\StoredFile;

final class DatabaseStorage extends AbstractStorage
{
    private ?DatabaseManager $resolvedManager = null;

    /**
     * @param string $connection Database connection alias.
     * @param string $fileTable Metadata table name.
     * @param string $contentTable Content table name.
     * @param DatabaseManager|null $manager Optional prebuilt database manager.
     * @param string $alias Public storage alias.
     */
    public function __construct(
        private readonly string $connection = 'main',
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
                $id = $this->manager()->insert(
                    sprintf(
                        'INSERT INTO %s (location, filename, mime_type, size, checksum, metadata, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        $this->fileTable,
                    ),
                    [$location, $name, $mimeType, $size, $checksum, $encodedMetadata, $timestamp, $timestamp],
                    $this->connection,
                );

                $this->manager()->insert(
                    sprintf('INSERT INTO %s (file_id, content) VALUES (?, ?)', $this->contentTable),
                    [$id, $contents],
                    $this->connection,
                );

                return;
            }

            $this->manager()->update(
                sprintf(
                    'UPDATE %s SET filename = ?, mime_type = ?, size = ?, checksum = ?, metadata = ?, updated_at = ? WHERE id = ?',
                    $this->fileTable,
                ),
                [$name, $mimeType, $size, $checksum, $encodedMetadata, $timestamp, (int) $existing['id']],
                $this->connection,
            );

            $this->manager()->update(
                sprintf('UPDATE %s SET content = ? WHERE file_id = ?', $this->contentTable),
                [$contents, (int) $existing['id']],
                $this->connection,
            );
        }, $this->connection);

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
        $rows = $this->manager()->select(
            sprintf(
                'SELECT c.content FROM %s c INNER JOIN %s f ON f.id = c.file_id WHERE f.location = ? LIMIT 1',
                $this->contentTable,
                $this->fileTable,
            ),
            [$location],
            $this->connection,
        );

        if ($rows === []) {
            throw new StorageException(sprintf('File "%s" does not exist in "%s" storage.', $location, $this->alias()));
        }

        return (string) $rows[0]['content'];
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

            $this->manager()->delete(
                sprintf('DELETE FROM %s WHERE file_id = ?', $this->contentTable),
                [$id],
                $this->connection,
            );

            $this->manager()->delete(
                sprintf('DELETE FROM %s WHERE id = ?', $this->fileTable),
                [$id],
                $this->connection,
            );
        }, $this->connection);

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
        $rows = $this->manager()->select(
            sprintf(
                'SELECT id, location, filename, mime_type, size, checksum, metadata, created_at, updated_at FROM %s WHERE location = ? LIMIT 1',
                $this->fileTable,
            ),
            [$location],
            $this->connection,
        );

        return $rows[0] ?? null;
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
        return $this->resolvedManager ??= new DatabaseManager($this->connection);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $identifier));
        }
    }
}
