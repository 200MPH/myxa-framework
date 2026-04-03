<?php

declare(strict_types=1);

namespace Myxa\Middleware;

use Closure;
use Myxa\Http\Request;
use Myxa\Http\Response;
use Myxa\Logging\LogLevel;
use Myxa\Logging\LoggerInterface;
use Myxa\Routing\RouteDefinition;

final class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(Request $request, Closure $next, RouteDefinition $route): mixed
    {
        $startedAt = microtime(true);
        $result = $next();

        $context = [
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        if ($result instanceof Response) {
            $context['status'] = $result->statusCode();
        }

        $this->logger->log(LogLevel::Info, 'HTTP request completed.', $context);

        return $result;
    }
}
