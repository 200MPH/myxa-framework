# Support

Support contains shared framework helpers, service-provider base classes, facades, and storage support wrappers.

## Facades

Available facades include:

- `DB`
- `Route`
- `Request`
- `Response`
- `Storage`
- `Event`
- `Debug`

## Example

```php
use Myxa\Support\Facades\DB;
use Myxa\Support\Facades\Route;
use Myxa\Support\Facades\Response;

Route::get('/users', static function () {
    $users = DB::select('SELECT id, email FROM users ORDER BY id ASC');

    return Response::json($users);
});
```

## Service Providers

Framework providers extend `ServiceProvider`:

```php
use Myxa\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // bind services
    }
}
```
