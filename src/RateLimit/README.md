# Rate Limiting

The rate limiter provides a small request-throttling layer and a matching middleware.

## Register the Provider

```php
use Myxa\RateLimit\RateLimitServiceProvider;

$app->register(new RateLimitServiceProvider());
```

## Use the Limiter

```php
$limiter = $app->make(\Myxa\RateLimit\RateLimiter::class);
$result = $limiter->consume('login:127.0.0.1', 5, 60);

if (!$result->allowed) {
    // reject request
}
```

## Route Middleware Example

```php
use Myxa\Support\Facades\Route;

Route::get('/api/users', [UserController::class, 'index'])
    ->middleware(\Myxa\Middleware\RateLimitMiddleware::for(60, 60, 'api'));
```

## Notes

- the default store is filesystem-backed unless you bind another store
- exceeded limits throw `TooManyRequestsException`
- the default HTTP exception handler adds rate-limit headers automatically
