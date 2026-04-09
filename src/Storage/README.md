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
use Myxa\Storage\StorageManager;

$storage = new StorageManager('local');

$storage->addStorage('local', new LocalStorage(__DIR__ . '/storage'));
$storage->addStorage('db', new DatabaseStorage(manager: new DatabaseManager('main')));
```

`addStorage()` also accepts a factory closure when you want lazy driver creation.

## Notes

- `StorageManager` works without `StorageServiceProvider`
- `StorageServiceProvider` registers the shared manager and initializes the facade
- the facade can also be pointed at a manager manually with `Storage::setManager(...)`
- local and database-backed storage drivers are available in the framework
- `StoredFile` metadata is returned for successful writes and lookups
