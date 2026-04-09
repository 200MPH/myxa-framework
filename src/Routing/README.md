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

## HTTP Methods

The router supports the common HTTP verbs directly:

```php
Route::get('/posts', [PostController::class, 'index']);
Route::post('/posts', [PostController::class, 'store']);
Route::put('/posts/{id}', [PostController::class, 'replace']);
Route::patch('/posts/{id}', [PostController::class, 'update']);
Route::delete('/posts/{id}', [PostController::class, 'destroy']);
Route::options('/posts', [PostController::class, 'options']);
Route::head('/posts', [PostController::class, 'head']);
```

Typical use:

- `put()` for full replacement updates
- `patch()` for partial updates
- `delete()` for record removal
- `options()` for capability/preflight responses
- `head()` for header-only responses

## Match Multiple Methods

Use `match()` when one route should accept several methods:

```php
Route::match(['GET', 'POST'], '/search', [SearchController::class, 'handle']);
```

Use `any()` when a route should accept the common HTTP methods:

```php
Route::any('/webhook', [WebhookController::class, 'handle']);
```

## Route Parameters

Routes support named path parameters:

```php
Route::get('/users/{id}', [UserController::class, 'show']);
Route::get('/posts/{postId}/comments/{commentId}', [CommentController::class, 'show']);
```

Handler arguments are resolved from the route parameters:

```php
Route::get('/users/{id}', static function (string $id) {
    return "User {$id}";
});
```

## Route Middleware

Middleware can be attached directly to a route:

```php
use Myxa\Middleware\AuthMiddleware;

Route::delete('/posts/{id}', [PostController::class, 'destroy'])
    ->middleware(AuthMiddleware::using('api'));

Route::get('/dashboard', [DashboardController::class, 'show'])
    ->middleware(AuthMiddleware::using('web'));
```

## Route Groups

Use groups to share a common path prefix:

```php
Route::group('/api', static function (): void {
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
});
```

You can also attach middleware to the whole group:

```php
use Myxa\Middleware\AuthMiddleware;

Route::group('/api', static function (): void {
    Route::get('/me', [ProfileController::class, 'show']);
    Route::patch('/me', [ProfileController::class, 'update']);
}, [AuthMiddleware::using('api')]);
```

## Middleware Groups

Use `middleware()` when you want shared middleware without adding a path prefix:

```php
use Myxa\Middleware\AuthMiddleware;

Route::middleware([AuthMiddleware::using('web')], static function (): void {
    Route::get('/dashboard', [DashboardController::class, 'show']);
    Route::get('/settings', [SettingsController::class, 'show']);
});
```

## Dispatch

```php
use Myxa\Support\Facades\Route;

$result = Route::dispatch();
```

You can also inspect routes directly:

```php
$exists = Route::has('GET', '/health');
$route = Route::find('GET', '/health');
$allRoutes = Route::routes();
```

## Notes

- routes support path parameters like `/users/{id}`
- handlers can be closures, object methods, or `[Controller::class, 'method']`
- middleware can be attached per route or by group
- auth middleware can be attached with `AuthMiddleware::using('web')` or `AuthMiddleware::using('api')`
- `match()` and `any()` are useful for shared endpoints like search pages and webhooks
- `RouteServiceProvider` registers the shared router and initializes the `Route` facade
