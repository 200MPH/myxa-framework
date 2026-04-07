# Auth

The auth layer provides a small guard-based authentication system.

Built-in guards:

- `web` via `SessionGuard`
- `api` via `BearerTokenGuard`

## Register the Provider

```php
use Myxa\Auth\AuthServiceProvider;

$app->register(new AuthServiceProvider());
```

## Provide User Resolvers

```php
use Myxa\Auth\BearerTokenResolverInterface;
use Myxa\Auth\SessionUserResolverInterface;
use Myxa\Http\Request;

$app->singleton(BearerTokenResolverInterface::class, static function () {
    return new class implements BearerTokenResolverInterface {
        public function resolve(string $token, Request $request): mixed
        {
            return $token === 'secret-token' ? ['id' => 1, 'name' => 'API User'] : null;
        }
    };
});

$app->singleton(SessionUserResolverInterface::class, static function () {
    return new class implements SessionUserResolverInterface {
        public function resolve(string $sessionId, Request $request): mixed
        {
            return $sessionId === 'session-123' ? ['id' => 2, 'name' => 'Web User'] : null;
        }
    };
});
```

## Use the Manager

```php
$auth = $app->make('auth');
$user = $auth->user($request);
$isAuthenticated = $auth->check($request, 'api');
```

## Notes

- guards are resolved through the container
- `AuthMiddleware` can be used to protect routes
- unauthenticated API requests can map to bearer challenges through the HTTP exception handler
