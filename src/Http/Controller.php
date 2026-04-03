<?php

declare(strict_types=1);

namespace Myxa\Http;

use Myxa\Container\Container;
use Myxa\Routing\RouteDefinition;
use Myxa\Routing\Exceptions\MethodNotAllowedException;

/**
 * Base HTTP controller that dispatches to an action based on the request method.
 */
abstract class Controller
{
    public function __construct(protected readonly Container $container)
    {
    }

    /**
     * Dispatch the request to the configured action for the HTTP method.
     *
     * @throws MethodNotAllowedException
     */
    final public function __invoke(Request $request, RouteDefinition $route): mixed
    {
        $action = $this->methodActionMap()[$request->method()] ?? null;

        if (!is_string($action) || !is_callable([$this, $action])) {
            throw new MethodNotAllowedException(
                $request->method(),
                $request->path(),
                $this->allowedMethods(),
            );
        }

        return $this->container->call([$this, $action], [
            ...(array) ($route->parametersForPath($request->path()) ?? []),
            Request::class => $request,
            RouteDefinition::class => $route,
            static::class => $this,
            self::class => $this,
            'controller' => $this,
            'request' => $request,
            'route' => $route,
        ]);
    }

    /**
     * Return the HTTP method to controller action mapping.
     *
     * @return array<string, string>
     */
    protected function methodActionMap(): array
    {
        return [
            'GET' => 'get',
            'POST' => 'post',
            'PUT' => 'put',
            'PATCH' => 'patch',
            'DELETE' => 'delete',
            'HEAD' => 'head',
            'OPTIONS' => 'options',
        ];
    }

    /**
     * Return the allowed HTTP methods based on implemented action methods.
     *
     * @return list<string>
     */
    protected function allowedMethods(): array
    {
        $allowedMethods = [];

        foreach ($this->methodActionMap() as $method => $action) {
            if (!is_callable([$this, $action])) {
                continue;
            }

            $allowedMethods[] = $method;
        }

        sort($allowedMethods);

        return $allowedMethods;
    }
}
