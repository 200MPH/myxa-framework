# Routing

The router keeps route registration and dispatch lightweight.

Available facade:

- `Route`

## Register Routes

```php
use Myxa\Support\Facades\Route;
use Myxa\Support\Facades\Response;

Route::get('/health', static function () {
    return Response::json(['ok' => true]);
});

Route::post('/users', [UserController::class, 'store']);
```

## Route Groups

```php
Route::group('/api', static function (): void {
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
});
```

## Middleware Groups

```php
Route::middleware(['auth'], static function (): void {
    Route::get('/dashboard', [DashboardController::class, 'show']);
});
```

## Dispatch

```php
use Myxa\Support\Facades\Route;

$result = Route::dispatch();
```

## Notes

- routes support path parameters like `/users/{id}`
- middleware can be attached per route or by group
- `RouteServiceProvider` registers the shared router and initializes the `Route` facade
