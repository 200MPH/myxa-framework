<?php

declare(strict_types=1);

namespace Test\Unit\Support\Facades;

use BadMethodCallException;
use Myxa\Application;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Connection\PdoConnectionConfig;
use Myxa\Support\Facades\Storage as StorageFacade;
use Myxa\Storage\Db\DatabaseStorage;
use Myxa\Storage\Local\LocalStorage;
use Myxa\Storage\StorageInterface;
use Myxa\Storage\StorageManager;
use Myxa\Storage\StoredFile;
use Myxa\Storage\StorageServiceProvider;
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
                'db' => static fn (): DatabaseStorage => new DatabaseStorage(
                    manager: new DatabaseManager(self::CONNECTION_ALIAS),
                ),
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

    public function testStorageManagerSupportsFactoriesAndProxyMethods(): void
    {
        $manager = new StorageManager(' local ');
        $storage = new StorageFacadeTestMemoryStorage();
        $manager->addStorage('local', fn (): StorageFacadeTestMemoryStorage => $storage);

        self::assertSame('local', $manager->getDefaultStorage());
        self::assertTrue($manager->hasStorage('local'));
        self::assertSame($storage, $manager->storage());

        $stored = $manager->put('docs/a.txt', 'alpha');

        self::assertSame('alpha', $manager->read('docs/a.txt'));
        self::assertTrue($manager->exists('docs/a.txt'));
        self::assertSame($stored->location(), $manager->get('docs/a.txt')?->location());
        self::assertTrue($manager->delete('docs/a.txt'));
        self::assertFalse($manager->exists('docs/a.txt'));
    }

    public function testStorageManagerValidatesFactoriesAndAliases(): void
    {
        $manager = new StorageManager('local');
        $manager->addStorage('local', new StorageFacadeTestMemoryStorage());

        try {
            $manager->addStorage('local', new StorageFacadeTestMemoryStorage());
            self::fail('Expected duplicate storage alias exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Storage alias "local" is already registered.', $exception->getMessage());
        }

        try {
            $manager->storage('missing');
            self::fail('Expected missing storage exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Storage alias "missing" is not registered.', $exception->getMessage());
        }

        try {
            new StorageManager(' ');
            self::fail('Expected invalid default storage exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Storage alias cannot be empty.', $exception->getMessage());
        }
    }

    public function testStorageManagerSupportsUploadsAndFactoryValidation(): void
    {
        $manager = new StorageManager('local');
        $storage = new StorageFacadeTestMemoryStorage();
        $manager->addStorage('local', $storage);

        $uploaded = $manager->upload([
            'name' => 'asset.txt',
            'type' => 'text/plain',
            'size' => 5,
            'tmp_name' => $this->makeUploadTempFile('asset'),
            'error' => 0,
        ], 'uploads');

        self::assertSame('uploads', $uploaded->location());
        self::assertSame('asset', $storage->read('uploads'));

        try {
            $manager->addStorage('broken', static fn (StorageManager $one, string $two): LocalStorage => throw new \RuntimeException('nope'));
            self::fail('Expected invalid factory signature exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Storage factory for alias "broken" must accept zero or one parameter.',
                $exception->getMessage(),
            );
        }

        $manager->addStorage('broken', static fn () => new \stdClass(), true);

        $this->expectException(\TypeError::class);
        $manager->storage('broken');
    }

    public function testFacadeThrowsClearExceptionForUnknownMethod(): void
    {
        $manager = new StorageManager('local');
        $manager->addStorage('local', new StorageFacadeTestMemoryStorage());
        StorageFacade::setManager($manager);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Storage facade method "foobar" is not supported.');

        StorageFacade::foobar();
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

final class StorageFacadeTestMemoryStorage implements StorageInterface
{
    /** @var array<string, StoredFile> */
    private array $files = [];

    /** @var array<string, string> */
    private array $contents = [];

    public function put(string $location, string $contents, array $options = []): StoredFile
    {
        $stored = new StoredFile(
            storage: 'memory',
            location: $location,
            name: $options['name'] ?? basename($location),
            size: strlen($contents),
            mimeType: $options['mime_type'] ?? null,
            checksum: sha1($contents),
            metadata: $options['metadata'] ?? [],
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
        return $this->contents[$location];
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
}
