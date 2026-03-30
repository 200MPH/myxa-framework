<?php

declare(strict_types=1);

namespace Myxa\Routing;

use RuntimeException;

/**
 * Thrown when no route matches a request path and method combination.
 */
final class RouteNotFoundException extends RuntimeException
{
    public function __construct(string $method, string $path)
    {
        parent::__construct(sprintf('Route [%s %s] was not found.', $method, $path));
    }
}
