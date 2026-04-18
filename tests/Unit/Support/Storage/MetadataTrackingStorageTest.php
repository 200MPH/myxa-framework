<?php

declare(strict_types=1);

namespace Test\Unit\Support\Storage;

use Myxa\Storage\Exceptions\StorageException;
use Myxa\Storage\Metadata\MetadataTrackingStorage;
use Myxa\Storage\Metadata\StorageMetadataRepositoryInterface;
use Myxa\Storage\StorageInterface;
use Myxa\Storage\StoredFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataTrackingStorage::class)]
final class MetadataTrackingStorageTest extends TestCase
{
    public function testMetadataTrackingStoragePersistsMetadataWhileDelegatingFileIo(): void
    {
        $storage = new MetadataTrackingTestStorage('s3');
        $repository = new MetadataTrackingTestRepository();
        $tracked = new MetadataTrackingStorage($storage, $repository, 'assets');

        $stored = $tracked->put(
            'docs/report.txt',
            'draft',
            ['mime_type' => 'text/plain', 'metadata' => ['category' => 'docs']],
        );

        self::assertSame('assets', $stored->storage());
        self::assertSame('draft', $tracked->read('docs/report.txt'));
        self::assertTrue($tracked->exists('docs/report.txt'));
        self::assertSame($stored, $tracked->get('docs/report.txt'));
        self::assertSame($stored, $repository->find('assets', 'docs/report.txt'));
    }

    public function testMetadataTrackingStorageCleansUpStaleMetadataWhenWrappedStorageIsMissing(): void
    {
        $storage = new MetadataTrackingTestStorage('local');
        $repository = new MetadataTrackingTestRepository();
        $tracked = new MetadataTrackingStorage($storage, $repository, 'uploads');

        $repository->save(new StoredFile(
            'uploads',
            'docs/missing.txt',
            'missing.txt',
            7,
            'text/plain',
            sha1('missing'),
            ['category' => 'docs'],
        ));

        self::assertNull($tracked->get('docs/missing.txt'));
        self::assertNull($repository->find('uploads', 'docs/missing.txt'));
    }

    public function testMetadataTrackingStorageFallsBackToWrappedStorageMetadataWhenRepositoryIsEmpty(): void
    {
        $storage = new MetadataTrackingTestStorage('s3');
        $repository = new MetadataTrackingTestRepository();
        $tracked = new MetadataTrackingStorage($storage, $repository, 'media');

        $storage->put('photos/a.jpg', 'jpg-bytes', ['mime_type' => 'image/jpeg']);

        $stored = $tracked->get('photos/a.jpg');

        self::assertInstanceOf(StoredFile::class, $stored);
        self::assertSame('media', $stored->storage());
        self::assertSame('image/jpeg', $stored->mimeType());
    }

    public function testMetadataTrackingStorageDeletesBothWrappedFileAndMetadata(): void
    {
        $storage = new MetadataTrackingTestStorage('local');
        $repository = new MetadataTrackingTestRepository();
        $tracked = new MetadataTrackingStorage($storage, $repository, 'files');

        $tracked->put('docs/delete-me.txt', 'bye');

        self::assertTrue($tracked->delete('docs/delete-me.txt'));
        self::assertFalse($tracked->exists('docs/delete-me.txt'));
        self::assertNull($repository->find('files', 'docs/delete-me.txt'));
        self::assertFalse($tracked->delete('docs/delete-me.txt'));
    }

    public function testMetadataTrackingStorageRollsBackStoredFileWhenMetadataSaveFails(): void
    {
        $storage = new MetadataTrackingTestStorage('s3');
        $repository = new MetadataTrackingTestRepository(shouldFailOnSave: true);
        $tracked = new MetadataTrackingStorage($storage, $repository, 'assets');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Metadata save failed.');

        try {
            $tracked->put('docs/report.txt', 'draft');
        } finally {
            self::assertFalse($storage->exists('docs/report.txt'));
        }
    }
}

final class MetadataTrackingTestStorage implements StorageInterface
{
    /** @var array<string, StoredFile> */
    private array $files = [];

    /** @var array<string, string> */
    private array $contents = [];

    public function __construct(
        private readonly string $alias = 'memory',
    ) {
    }

    public function put(string $location, string $contents, array $options = []): StoredFile
    {
        $stored = new StoredFile(
            $this->alias,
            $location,
            $options['name'] ?? basename($location),
            strlen($contents),
            $options['mime_type'] ?? null,
            sha1($contents),
            $options['metadata'] ?? [],
        );

        $this->files[$location] = $stored;
        $this->contents[$location] = $contents;

        return $stored;
    }

    public function get(string $location): ?StoredFile
    {
        return $this->files[$location] ?? null;
    }

    public function read(string $location): string
    {
        return $this->contents[$location]
            ?? throw new StorageException(sprintf('Missing file "%s".', $location));
    }

    public function delete(string $location): bool
    {
        if (!isset($this->files[$location])) {
            return false;
        }

        unset($this->files[$location], $this->contents[$location]);

        return true;
    }

    public function exists(string $location): bool
    {
        return isset($this->files[$location]);
    }

    public function alias(): string
    {
        return $this->alias;
    }
}

final class MetadataTrackingTestRepository implements StorageMetadataRepositoryInterface
{
    /** @var array<string, StoredFile> */
    private array $files = [];

    public function __construct(
        private readonly bool $shouldFailOnSave = false,
    ) {
    }

    public function save(StoredFile $file): void
    {
        if ($this->shouldFailOnSave) {
            throw new StorageException('Metadata save failed.');
        }

        $this->files[$this->key($file->storage(), $file->location())] = $file;
    }

    public function find(string $storage, string $location): ?StoredFile
    {
        return $this->files[$this->key($storage, $location)] ?? null;
    }

    public function delete(string $storage, string $location): bool
    {
        $key = $this->key($storage, $location);

        if (!isset($this->files[$key])) {
            return false;
        }

        unset($this->files[$key]);

        return true;
    }

    private function key(string $storage, string $location): string
    {
        return $storage . '::' . $location;
    }
}
