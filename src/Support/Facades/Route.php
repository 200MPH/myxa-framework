<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use BadMethodCallException;
use Myxa\Routing\RouteDefinition;
use Myxa\Routing\Router;

/**
 * Small static facade for the application router.
 */
final class Route
{
    private static ?Router $router = null;

    /**
     * Set the underlying router instance used by the facade.
     */
    public static function setRouter(Router $router): void
    {
        self::$router = $router;
    }

    /**
     * Clear the currently stored router instance.
     */
    public static function clearRouter(): void
    {
        self::$router = null;
    }

    /**
     * Return the underlying router instance.
     */
    public static function getRouter(): Router
    {
        if (!self::$router instanceof Router) {
            throw new \RuntimeException('Route facade has not been initialized.');
        }

        return self::$router;
    }

    /**
     * Register a route for one or many HTTP methods.
     *
     * @param string|list<string> $methods
     * @param mixed $handler
     */
    public static function match(array|string $methods, string $path, mixed $handler): RouteDefinition
    {
        return self::getRouter()->match($methods, $path, $handler);
    }

    /**
     * Register a route for a single custom HTTP method.
     *
     * @param mixed $handler
     */
    public static function add(string $method, string $path, mixed $handler): RouteDefinition
    {
        return self::getRouter()->add($method, $path, $handler);
    }

    /**
     * Register a GET route.
     *
     * @param mixed $handler
     */
    public static function get(string $path, mixed $handler): RouteDefinition
    {
        return self::getRouter()->get($path, $handler);
    }

    /**
     * Register a POST route.
     *
     * @param mixed $handler
     */
    public static function post(string $path, mixed $handler): RouteDefinition
    {
        return self::getRouter()->post($path, $handler);
    }

    /**
     * Register a PUT route.
     *
     * @param mixed $handler
     */
    public static function put(string $path, mixed $handler): RouteDefinition
    {
        return self::getRouter()->put($path, $handler);
    }

    /**
     * Register a PATCH route.
     *
     * @param mixed $handler
     */
    public static function patch(string $path, mixed $handler): RouteDefinition
    {
        return self::getRouter()->patch($path, $handler);
    }

    /**
     * Register a DELETE route.
     *
     * @param mixed $handler
     */
    public static function delete(string $path, mixed $handler): RouteDefinition
    {
        return self::getRouter()->delete($path, $handler);
    }

    /**
     * Register an OPTIONS route.
     *
     * @param mixed $handler
     */
    public static function options(string $path, mixed $handler): RouteDefinition
    {
        return self::getRouter()->options($path, $handler);
    }

    /**
     * Register a HEAD route.
     *
     * @param mixed $handler
     */
    public static function head(string $path, mixed $handler): RouteDefinition
    {
        return self::getRouter()->head($path, $handler);
    }

    /**
     * Register a route for the common HTTP methods.
     *
     * @param mixed $handler
     */
    public static function any(string $path, mixed $handler): RouteDefinition
    {
        return self::getRouter()->any($path, $handler);
    }

    /**
     * Register a grouped set of routes behind a shared prefix.
     */
    public static function group(string $prefix, callable $routes, mixed $middlewares = []): void
    {
        self::getRouter()->group($prefix, $routes, $middlewares);
    }

    /**
     * Register a middleware-only group.
     */
    public static function middleware(mixed $middlewares, callable $routes): void
    {
        self::getRouter()->middleware($middlewares, $routes);
    }

    /**
     * Return all registered routes.
     *
     * @return list<RouteDefinition>
     */
    public static function routes(): array
    {
        return self::getRouter()->routes();
    }

    /**
     * Determine whether a route exists for the given method and path.
     */
    public static function has(string $method, string $path): bool
    {
        return self::getRouter()->has($method, $path);
    }

    /**
     * Resolve the matching route for a method and path pair.
     */
    public static function find(string $method, string $path): RouteDefinition
    {
        return self::getRouter()->find($method, $path);
    }

    /**
     * Dispatch the provided request, or the current container request.
     *
     * @param array<string, mixed> $parameters
     */
    public static function dispatch(?\Myxa\Http\Request $request = null, array $parameters = []): mixed
    {
        return self::getRouter()->dispatch($request, $parameters);
    }

    /**
     * Forward unknown static calls to the underlying router.
     *
     * @param list<mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        if (!method_exists(self::getRouter(), $method)) {
            throw new BadMethodCallException(sprintf('Route facade method "%s" is not supported.', $method));
        }

        return self::getRouter()->{$method}(...$arguments);
    }
}
