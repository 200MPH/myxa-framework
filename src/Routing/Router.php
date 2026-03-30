<?php

declare(strict_types=1);

namespace Myxa\Routing;

use Closure;
use InvalidArgumentException;
use Myxa\Container\Container;
use Myxa\Http\Request;
use Myxa\Middleware\MiddlewareInterface;

/**
 * Small in-memory router for registering and dispatching request handlers.
 */
final class Router
{
    /**
     * @var list<RouteDefinition>
     */
    private array $routes = [];

    /**
     * @var list<string>
     */
    private array $groupPrefixes = [];

    /**
     * @var list<list<mixed>>
     */
    private array $groupMiddlewares = [];

    public function __construct(private readonly Container $container)
    {
        $this->container->instance(self::class, $this);
        $this->container->instance('router', $this);
    }

    /**
     * Register a route for one or many HTTP methods.
     *
     * @param string|list<string> $methods
     * @param mixed $handler
     */
    public function match(array|string $methods, string $path, mixed $handler): RouteDefinition
    {
        $route = new RouteDefinition(
            $this->normalizeMethods($methods),
            $this->applyGroupPrefix($path),
            $handler,
            $this->currentGroupMiddlewares(),
        );

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Register a route for a single custom HTTP method.
     *
     * @param mixed $handler
     */
    public function add(string $method, string $path, mixed $handler): RouteDefinition
    {
        return $this->match([$method], $path, $handler);
    }

    /**
     * Register a GET route.
     *
     * @param mixed $handler
     */
    public function get(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('GET', $path, $handler);
    }

    /**
     * Register a POST route.
     *
     * @param mixed $handler
     */
    public function post(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('POST', $path, $handler);
    }

    /**
     * Register a PUT route.
     *
     * @param mixed $handler
     */
    public function put(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('PUT', $path, $handler);
    }

    /**
     * Register a PATCH route.
     *
     * @param mixed $handler
     */
    public function patch(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('PATCH', $path, $handler);
    }

    /**
     * Register a DELETE route.
     *
     * @param mixed $handler
     */
    public function delete(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('DELETE', $path, $handler);
    }

    /**
     * Register an OPTIONS route.
     *
     * @param mixed $handler
     */
    public function options(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('OPTIONS', $path, $handler);
    }

    /**
     * Register a HEAD route.
     *
     * @param mixed $handler
     */
    public function head(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('HEAD', $path, $handler);
    }

    /**
     * Register a route that accepts the common HTTP methods.
     *
     * @param mixed $handler
     */
    public function any(string $path, mixed $handler): RouteDefinition
    {
        return $this->match(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
            $path,
            $handler,
        );
    }

    /**
     * Register a nested route group with a shared path prefix.
     */
    public function group(string $prefix, callable $routes, mixed $middlewares = []): void
    {
        $this->groupPrefixes[] = $this->normalizePrefix($prefix);
        $this->groupMiddlewares[] = $this->normalizeMiddlewares($middlewares);

        try {
            $this->container->call($routes, [
                self::class => $this,
                'router' => $this,
            ]);
        } finally {
            array_pop($this->groupMiddlewares);
            array_pop($this->groupPrefixes);
        }
    }

    /**
     * Register a middleware-only group without adding a path prefix.
     */
    public function middleware(mixed $middlewares, callable $routes): void
    {
        $this->groupMiddlewares[] = $this->normalizeMiddlewares($middlewares);

        try {
            $this->container->call($routes, [
                self::class => $this,
                'router' => $this,
            ]);
        } finally {
            array_pop($this->groupMiddlewares);
        }
    }

    /**
     * Return the registered routes.
     *
     * @return list<RouteDefinition>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Determine whether a route exists for the given method and path.
     */
    public function has(string $method, string $path): bool
    {
        try {
            $this->find($method, $path);

            return true;
        } catch (RouteNotFoundException | MethodNotAllowedException) {
            return false;
        }
    }

    /**
     * Resolve the matching route for a method and path pair.
     *
     * @throws MethodNotAllowedException
     * @throws RouteNotFoundException
     */
    public function find(string $method, string $path): RouteDefinition
    {
        [$route] = $this->resolve($method, $path);

        return $route;
    }

    /**
     * Dispatch the provided request, or the current container request.
     *
     * @param array<string, mixed> $parameters
     *
     * @throws MethodNotAllowedException
     * @throws RouteNotFoundException
     */
    public function dispatch(?Request $request = null, array $parameters = []): mixed
    {
        $request ??= $this->container->make(Request::class);
        [$route, $routeParameters] = $this->resolve($request->method(), $request->path());
        $context = [
            ...$parameters,
            ...$routeParameters,
            Request::class => $request,
            RouteDefinition::class => $route,
            self::class => $this,
            'request' => $request,
            'route' => $route,
            'router' => $this,
            'parameters' => $routeParameters,
            'routeParameters' => $routeParameters,
        ];

        $pipeline = fn (): mixed => $this->container->call($route->handler(), $context);

        foreach (array_reverse($route->middlewares()) as $middleware) {
            $next = $pipeline;
            $pipeline = fn (): mixed => $this->callMiddleware($middleware, $request, $route, $routeParameters, $next);
        }

        return $pipeline();
    }

    /**
     * Resolve the matching route and extracted parameters.
     *
     * @return array{0: RouteDefinition, 1: array<string, string>}
     *
     * @throws MethodNotAllowedException
     * @throws RouteNotFoundException
     */
    private function resolve(string $method, string $path): array
    {
        $normalizedMethod = $this->normalizeMethod($method);
        $normalizedPath = $this->normalizePath($path);
        $allowedMethods = [];
        $matchedRoute = null;
        $matchedParameters = [];

        foreach ($this->routes as $route) {
            $routeParameters = $route->parametersForPath($normalizedPath);
            if ($routeParameters === null) {
                continue;
            }

            if ($route->allowsMethod($normalizedMethod)) {
                if ($routeParameters === []) {
                    return [$route, []];
                }

                $matchedRoute ??= $route;
                $matchedParameters = $matchedRoute === $route ? $routeParameters : $matchedParameters;

                continue;
            }

            $allowedMethods = [...$allowedMethods, ...$route->methods()];
        }

        if ($matchedRoute instanceof RouteDefinition) {
            return [$matchedRoute, $matchedParameters];
        }

        if ($allowedMethods !== []) {
            $allowedMethods = array_values(array_unique($allowedMethods));
            sort($allowedMethods);

            throw new MethodNotAllowedException($normalizedMethod, $normalizedPath, $allowedMethods);
        }

        throw new RouteNotFoundException($normalizedMethod, $normalizedPath);
    }

    /**
     * Normalize one or many HTTP methods.
     *
     * @param string|list<string> $methods
     *
     * @return list<string>
     */
    private function normalizeMethods(array|string $methods): array
    {
        $methods = is_string($methods) ? [$methods] : $methods;

        if ($methods === []) {
            throw new InvalidArgumentException('Route methods cannot be empty.');
        }

        $normalized = [];

        foreach ($methods as $method) {
            $method = $this->normalizeMethod($method);

            if ($method === '') {
                throw new InvalidArgumentException('Route method cannot be empty.');
            }

            $normalized[$method] = true;
        }

        return array_keys($normalized);
    }

    /**
     * Normalize a single HTTP method.
     */
    private function normalizeMethod(string $method): string
    {
        return strtoupper(trim($method));
    }

    /**
     * Normalize a route path.
     */
    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = '/' . ltrim($path, '/');

        return rtrim($path, '/') ?: '/';
    }

    /**
     * Normalize a group prefix and keep root groups empty.
     */
    private function normalizePrefix(string $prefix): string
    {
        $normalizedPrefix = $this->normalizePath($prefix);

        return $normalizedPrefix === '/' ? '' : $normalizedPrefix;
    }

    /**
     * Combine the current group prefix stack with a route path.
     */
    private function applyGroupPrefix(string $path): string
    {
        $normalizedPath = $this->normalizePath($path);
        $prefix = implode('', $this->groupPrefixes);

        if ($normalizedPath === '/') {
            return $prefix === '' ? '/' : $prefix;
        }

        return $this->normalizePath($prefix . $normalizedPath);
    }

    /**
     * Flatten the current nested group middleware stack.
     *
     * @return list<mixed>
     */
    private function currentGroupMiddlewares(): array
    {
        $middlewares = [];

        foreach ($this->groupMiddlewares as $groupMiddlewares) {
            $middlewares = [...$middlewares, ...$groupMiddlewares];
        }

        return $middlewares;
    }

    /**
     * Normalize middleware input into a flat list.
     *
     * @return list<mixed>
     */
    private function normalizeMiddlewares(mixed $middlewares): array
    {
        if ($middlewares === [] || $middlewares === null) {
            return [];
        }

        if (!is_array($middlewares)) {
            return [$middlewares];
        }

        $normalized = [];

        foreach ($middlewares as $middleware) {
            if (is_array($middleware)) {
                $normalized = [...$normalized, ...$this->normalizeMiddlewares($middleware)];
                continue;
            }

            $normalized[] = $middleware;
        }

        return $normalized;
    }

    /**
     * Invoke a single middleware definition.
     *
     * @param array<string, string> $routeParameters
     */
    private function callMiddleware(
        mixed $middleware,
        Request $request,
        RouteDefinition $route,
        array $routeParameters,
        Closure $next,
    ): mixed {
        $target = $this->resolveMiddlewareTarget($middleware);

        return $this->container->call($target, [
            ...$routeParameters,
            Request::class => $request,
            RouteDefinition::class => $route,
            self::class => $this,
            'request' => $request,
            'route' => $route,
            'router' => $this,
            'next' => $next,
            'parameters' => $routeParameters,
            'routeParameters' => $routeParameters,
        ]);
    }

    /**
     * Resolve middleware classes to an invokable callable target.
     */
    private function resolveMiddlewareTarget(mixed $middleware): mixed
    {
        if (is_string($middleware) && class_exists($middleware)) {
            if (is_subclass_of($middleware, MiddlewareInterface::class) || method_exists($middleware, 'handle')) {
                return [$middleware, 'handle'];
            }

            return $middleware;
        }

        if (is_object($middleware) && !$middleware instanceof Closure) {
            if ($middleware instanceof MiddlewareInterface || method_exists($middleware, 'handle')) {
                return [$middleware, 'handle'];
            }
        }

        return $middleware;
    }
}
