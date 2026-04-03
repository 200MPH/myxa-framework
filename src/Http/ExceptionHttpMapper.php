<?php

declare(strict_types=1);

namespace Myxa\Http;

use InvalidArgumentException;
use Myxa\Auth\AuthenticationException;
use Myxa\Database\Model\ModelNotFoundException;
use Myxa\RateLimit\TooManyRequestsException;
use Myxa\Routing\MethodNotAllowedException;
use Myxa\Routing\RouteNotFoundException;
use Throwable;

final class ExceptionHttpMapper
{
    /**
     * Resolve the HTTP status code that should be used for an exception.
     */
    public static function statusCodeFor(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof RouteNotFoundException,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof MethodNotAllowedException => 405,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof TooManyRequestsException => 429,
            $exception instanceof InvalidArgumentException => 400,
            default => 500,
        };
    }
}
