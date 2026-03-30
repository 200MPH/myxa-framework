<?php

declare(strict_types=1);

namespace Myxa\Middleware;

use Closure;
use Myxa\Http\Request;
use Myxa\Routing\RouteDefinition;

/**
 * Contract for HTTP middleware executed around route handlers.
 */
interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next, RouteDefinition $route): mixed;
}
