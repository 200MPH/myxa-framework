# Middleware

Middleware wraps route dispatch with reusable request logic.

Built-in examples include:

- `AuthMiddleware`
- `RateLimitMiddleware`
- `RequestLoggingMiddleware`

## Route Usage

```php
use Myxa\Support\Facades\Route;
use Myxa\Middleware\AuthMiddleware;

Route::get('/dashboard', [DashboardController::class, 'show'])
    ->middleware(AuthMiddleware::for('web'));
```

## Group Usage

```php
Route::middleware([
    \Myxa\Middleware\RequestLoggingMiddleware::class,
], static function (): void {
    Route::get('/reports', [ReportController::class, 'index']);
});
```

## Notes

- middleware receives `Request`, `Closure $next`, and `RouteDefinition`
- middleware can be registered as classes or configured helper instances
- routing composes middleware around the final route handler
