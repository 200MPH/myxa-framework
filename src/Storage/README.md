# Storage

The storage layer supports named storage drivers with a shared manager.

Available facade:

- `Storage`

## Use StorageManager Directly

If you do not want to register `StorageServiceProvider`, you can work with `StorageManager` directly:

```php
use Myxa\Storage\StorageManager;
use Myxa\Storage\Local\LocalStorage;

$storage = new StorageManager('local');
$storage->addStorage('local', new LocalStorage(__DIR__ . '/storage'));

$stored = $storage->put('avatars/john.txt', 'hello');
$contents = $storage->read('avatars/john.txt');
$exists = $storage->exists('avatars/john.txt');
```

This is the simplest option for standalone usage, tests, or small scripts.

## Use the Facade After Registering the Provider

In application code, register `StorageServiceProvider` so the shared manager is available through the facade:

```php
use Myxa\Application;
use Myxa\Storage\Local\LocalStorage;
use Myxa\Storage\StorageServiceProvider;

$app = new Application();

$app->register(new StorageServiceProvider(
    storages: [
        'local' => new LocalStorage(__DIR__ . '/storage'),
    ],
    defaultStorage: 'local',
));

$app->boot();
```

Then use the facade:

```php
use Myxa\Support\Facades\Storage;

Storage::put('avatars/john.txt', 'hello');

$contents = Storage::read('avatars/john.txt');
$exists = Storage::exists('avatars/john.txt');
```

## Upload Files

With the facade:

```php
use Myxa\Support\Facades\Request;
use Myxa\Support\Facades\Storage;

$stored = Storage::upload(
    Request::file('avatar'),
    'avatars',
    ['allowed_extensions' => ['jpg', 'png']],
);
```

With the manager directly:

```php
$stored = $storage->upload(
    $_FILES['avatar'],
    'avatars',
    ['allowed_extensions' => ['jpg', 'png']],
);
```

## Named Drivers

You can register multiple storage drivers and target them by alias.

Using the facade:

```php
$stored = Storage::put('logs/app.log', 'message', storage: 'local');
$meta = Storage::get('logs/app.log', 'local');
```

Using the manager:

```php
$stored = $storage->put('logs/app.log', 'message', storage: 'local');
$meta = $storage->get('logs/app.log', 'local');
```

## Register Drivers

Example with multiple drivers:

```php
use Myxa\Database\DatabaseManager;
use Myxa\Storage\Db\DatabaseStorage;
use Myxa\Storage\Local\LocalStorage;
use Myxa\Storage\Metadata\MetadataTrackingStorage;
use Myxa\Storage\Metadata\StorageMetadataRepositoryInterface;
use Myxa\Storage\StorageManager;
use Myxa\Storage\S3\S3Storage;

$storage = new StorageManager('local');

$storage->addStorage('local', new LocalStorage(__DIR__ . '/storage'));
$storage->addStorage('db', new DatabaseStorage(manager: new DatabaseManager('main')));
$storage->addStorage('s3', new S3Storage(
    bucket: 'my-app-files',
    region: 'eu-central-1',
    accessKey: $_ENV['AWS_ACCESS_KEY_ID'],
    secretKey: $_ENV['AWS_SECRET_ACCESS_KEY'],
    sessionToken: $_ENV['AWS_SESSION_TOKEN'] ?? null,
));
```

`addStorage()` also accepts a factory closure when you want lazy driver creation.

## Track Metadata In Your App Database

If you want file contents on `local` or `s3` but still want searchable metadata in your app database,
wrap the real storage driver with `MetadataTrackingStorage`.

The framework provides:

- `MetadataTrackingStorage`
- `StorageMetadataRepositoryInterface`

Your app provides the concrete repository implementation for its own schema.

```php
use Myxa\Storage\Metadata\MetadataTrackingStorage;
use Myxa\Storage\Metadata\StorageMetadataRepositoryInterface;
use Myxa\Storage\S3\S3Storage;

final class AppFileMetadataRepository implements StorageMetadataRepositoryInterface
{
    public function save(\Myxa\Storage\StoredFile $file): void
    {
        // Persist storage/location/name/size/checksum/mime_type/metadata in your DB.
    }

    public function find(string $storage, string $location): ?\Myxa\Storage\StoredFile
    {
        // Rebuild and return a StoredFile from your DB row.
    }

    public function delete(string $storage, string $location): bool
    {
        // Delete the metadata row and return whether one existed.
    }
}

$storage->addStorage('uploads', new MetadataTrackingStorage(
    new S3Storage(
        bucket: 'my-app-files',
        region: 'eu-central-1',
        accessKey: $_ENV['AWS_ACCESS_KEY_ID'],
        secretKey: $_ENV['AWS_SECRET_ACCESS_KEY'],
    ),
    new AppFileMetadataRepository(),
    'uploads',
));
```

With that setup:

- file reads and writes still go to the wrapped storage driver
- `get()` returns tracked metadata from your repository when available
- stale metadata is cleaned up automatically if the physical file disappears
- you keep your DB schema in the consumer app instead of locking the framework to one table shape

## Notes

- `StorageManager` works without `StorageServiceProvider`
- `StorageServiceProvider` registers the shared manager and initializes the facade
- the facade can also be pointed at a manager manually with `Storage::setManager(...)`
- local, database-backed, S3-compatible, and metadata-tracking storage drivers are available in the framework
- `StoredFile` metadata is returned for successful writes and lookups
