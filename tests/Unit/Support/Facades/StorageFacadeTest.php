<?php

declare(strict_types=1);

namespace Test\Unit\Support\Facades;

use Myxa\Application;
use Myxa\Database\PdoConnection;
use Myxa\Database\PdoConnectionConfig;
use Myxa\Support\Facades\Storage as StorageFacade;
use Myxa\Support\Storage\Db\DatabaseStorage;
use Myxa\Support\Storage\Local\LocalStorage;
use Myxa\Support\Storage\StorageManager;
use Myxa\Support\Storage\StorageServiceProvider;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(StorageFacade::class)]
#[CoversClass(StorageManager::class)]
#[CoversClass(StorageServiceProvider::class)]
final class StorageFacadeTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'file-facade-test';

    private string $localRoot;

    /** @var list<string> */
    private array $tempUploads = [];

    protected function setUp(): void
    {
        $this->localRoot = sys_get_temp_dir() . '/myxa-file-facade-' . bin2hex(random_bytes(6));
        PdoConnection::register(self::CONNECTION_ALIAS, $this->makeInMemoryConnection(), true);
    }

    protected function tearDown(): void
    {
        StorageFacade::clearManager();
        PdoConnection::unregister(self::CONNECTION_ALIAS);

        foreach ($this->tempUploads as $tempUpload) {
            if (is_file($tempUpload)) {
                unlink($tempUpload);
            }
        }

        $this->deleteDirectory($this->localRoot);
    }

    public function testServiceProviderBootstrapsFacadeAndSupportsMultipleStorages(): void
    {
        $app = new Application();
        $app->register(new StorageServiceProvider(
            storages: [
                'local' => new LocalStorage($this->localRoot),
                'db' => static fn (): DatabaseStorage => new DatabaseStorage(connection: self::CONNECTION_ALIAS),
            ],
            defaultStorage: 'local',
        ));

        $app->boot();

        $manager = $app->make(StorageManager::class);

        self::assertSame($manager, $app->make('storage'));
        self::assertSame($manager, StorageFacade::getManager());

        $localFile = StorageFacade::put('notes/welcome.txt', 'hello');
        $dbFile = StorageFacade::put('notes/welcome.txt', 'persisted', storage: 'db');
        $uploaded = StorageFacade::upload([
            'name' => 'banner.txt',
            'type' => 'text/plain',
            'size' => 6,
            'tmp_name' => $this->makeUploadTempFile('banner'),
            'error' => 0,
        ]);

        self::assertSame('local', $localFile->storage());
        self::assertSame('db', $dbFile->storage());
        self::assertTrue(StorageFacade::exists('notes/welcome.txt'));
        self::assertSame('hello', StorageFacade::read('notes/welcome.txt'));
        self::assertSame('persisted', StorageFacade::read('notes/welcome.txt', 'db'));
        self::assertSame('banner', StorageFacade::read($uploaded->location()));
    }

    private function makeUploadTempFile(string $contents): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'myxa-facade-upload-');
        if (!is_string($tempFile)) {
            self::fail('Unable to create temporary upload file.');
        }

        file_put_contents($tempFile, $contents);
        $this->tempUploads[] = $tempFile;

        return $tempFile;
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }

    private function makeInMemoryConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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
            . 'content BLOB NOT NULL'
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
}
