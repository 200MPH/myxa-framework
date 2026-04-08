# Storage

The storage layer supports named storage drivers with a shared manager.

Available facade:

- `Storage`

## Basic Usage

```php
use Myxa\Support\Facades\Storage;

Storage::put('avatars/john.txt', 'hello');

$contents = Storage::read('avatars/john.txt');
$exists = Storage::exists('avatars/john.txt');
```

## Upload Files

```php
use Myxa\Support\Facades\Storage;
use Myxa\Support\Facades\Request;

$stored = Storage::upload(
    Request::file('avatar'),
    'avatars',
    ['allowed_extensions' => ['jpg', 'png']],
);
```

## Named Drivers

```php
$stored = Storage::put('logs/app.log', 'message', storage: 'local');
$meta = Storage::get('logs/app.log', 'local');
```

## Notes

- `StorageServiceProvider` registers the shared manager and initializes the facade
- local and database-backed storage drivers are available in the framework
- `StoredFile` metadata is returned for successful writes and lookups
