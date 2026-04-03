<?php

declare(strict_types=1);

namespace Myxa\Middleware;

use Closure;
use Myxa\Http\ExceptionHandlerInterface;
use Myxa\Http\Request;
use Myxa\Routing\RouteDefinition;
use Throwable;

final class HandleExceptionsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ExceptionHandlerInterface $handler)
    {
    }

    public function handle(Request $request, Closure $next, RouteDefinition $route): mixed
    {
        try {
            return $next();
        } catch (Throwable $exception) {
            return $this->handler->render($exception, $request);
        }
    }
}
