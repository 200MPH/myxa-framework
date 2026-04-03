<?php

declare(strict_types=1);

namespace Test\Unit\Support\Storage;

use Myxa\Database\DatabaseManager;
use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Connection\PdoConnectionConfig;
use Myxa\Storage\Db\DatabaseStorage;
use Myxa\Storage\StorageException;
use Myxa\Storage\StoredFile;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(DatabaseStorage::class)]
#[CoversClass(StoredFile::class)]
#[CoversClass(StorageException::class)]
final class DatabaseStorageTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'file-storage-test';

    protected function setUp(): void
    {
        PdoConnection::register(self::CONNECTION_ALIAS, $this->makeInMemoryConnection(), true);
    }

    protected function tearDown(): void
    {
        PdoConnection::unregister(self::CONNECTION_ALIAS);
    }

    public function testDatabaseStoragePersistsUpdatesAndDeletesFiles(): void
    {
        $storage = new DatabaseStorage(manager: $this->makeManager());

        $stored = $storage->put(
            'docs/report.txt',
            'draft',
            ['name' => 'report.txt', 'mime_type' => 'text/plain', 'metadata' => ['category' => 'docs']],
        );

        self::assertSame('db', $stored->storage());
        self::assertSame('docs/report.txt', $stored->location());
        self::assertSame('report.txt', $stored->name());
        self::assertSame(5, $stored->size());
        self::assertSame('text/plain', $stored->mimeType());
        self::assertSame(sha1('draft'), $stored->checksum());
        self::assertSame('docs', $stored->metadata('category'));
        self::assertIsInt($stored->metadata('database_id'));
        self::assertTrue($storage->exists('docs/report.txt'));
        self::assertSame('draft', $storage->read('docs/report.txt'));

        $updated = $storage->put(
            'docs/report.txt',
            'final copy',
            ['name' => 'report-final.txt', 'metadata' => ['category' => 'published']],
        );

        self::assertSame('report-final.txt', $updated->name());
        self::assertSame(10, $updated->size());
        self::assertSame('published', $updated->metadata('category'));
        self::assertSame('final copy', $storage->read('docs/report.txt'));
        self::assertTrue($storage->delete('docs/report.txt'));
        self::assertFalse($storage->exists('docs/report.txt'));
        self::assertNull($storage->get('docs/report.txt'));
    }

    public function testDatabaseStorageThrowsWhenReadingMissingFile(): void
    {
        $storage = new DatabaseStorage(manager: $this->makeManager());

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('File "missing.txt" does not exist in "db" storage.');

        $storage->read('missing.txt');
    }

    private function makeInMemoryConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(
            'CREATE TABLE files ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'location TEXT NOT NULL UNIQUE, '
            . 'filename TEXT NOT NULL, '
            . 'mime_type TEXT NULL, '
            . 'size INTEGER NOT NULL, '
            . 'checksum TEXT NULL, '
            . 'metadata TEXT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE file_contents ('
            . 'file_id INTEGER PRIMARY KEY, '
            . 'content BLOB NOT NULL, '
            . 'FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE'
            . ')',
        );

        $connection = new PdoConnection(
            new PdoConnectionConfig(
                engine: 'mysql',
                database: 'placeholder',
                host: '127.0.0.1',
            ),
        );

        $pdoProperty = new ReflectionProperty(PdoConnection::class, 'pdo');
        $pdoProperty->setValue($connection, $pdo);

        return $connection;
    }

    private function makeManager(): DatabaseManager
    {
        return new DatabaseManager(self::CONNECTION_ALIAS);
    }
}
