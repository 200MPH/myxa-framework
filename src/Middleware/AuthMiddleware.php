<?php

declare(strict_types=1);

namespace Myxa\Middleware;

use Closure;
use Myxa\Auth\AuthManager;
use Myxa\Auth\Exceptions\AuthenticationException;
use Myxa\Http\Request;
use Myxa\Routing\RouteDefinition;

/**
 * Ensure the current request is authenticated for the selected guard.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly string $guard = 'web',
        private readonly string $redirectTo = '/login',
    ) {
    }

    public function handle(Request $request, Closure $next, RouteDefinition $route): mixed
    {
        if ($this->auth->check($request, $this->guard)) {
            return $next();
        }

        throw new AuthenticationException($this->guard, $this->redirectTo);
    }

    /**
     * Build a route middleware callable that targets a named guard.
     */
    public static function using(string $guard, string $redirectTo = '/login'): Closure
    {
        return static function (
            Request $request,
            Closure $next,
            RouteDefinition $route,
            AuthManager $auth,
        ) use ($guard, $redirectTo): mixed {
            return (new self($auth, $guard, $redirectTo))->handle($request, $next, $route);
        };
    }
}
