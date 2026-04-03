<?php

declare(strict_types=1);

namespace Myxa\Middleware;

use Closure;
use Myxa\Http\Request;
use Myxa\Http\Response;
use Myxa\RateLimit\Exceptions\TooManyRequestsException;
use Myxa\RateLimit\RateLimiter;
use Myxa\RateLimit\RateLimitResult;
use Myxa\Routing\RouteDefinition;

/**
 * Enforce a request rate limit for the current route.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $maxAttempts = 60,
        private readonly int $decaySeconds = 60,
        private readonly ?string $prefix = null,
    ) {
    }

    public function handle(Request $request, Closure $next, RouteDefinition $route): mixed
    {
        $result = $this->limiter->consume(
            $this->resolveKey($request),
            $this->maxAttempts,
            $this->decaySeconds,
        );

        if ($result->tooManyAttempts) {
            throw new TooManyRequestsException($result);
        }

        $response = $next();

        if ($response instanceof Response) {
            $this->applyHeaders($response, $result);
        }

        return $response;
    }

    /**
     * Build a route middleware callable with explicit rate limit settings.
     */
    public static function using(int $maxAttempts, int $decaySeconds, ?string $prefix = null): Closure
    {
        return static function (
            Request $request,
            Closure $next,
            RouteDefinition $route,
            RateLimiter $limiter,
        ) use ($maxAttempts, $decaySeconds, $prefix): mixed {
            return (new self($limiter, $maxAttempts, $decaySeconds, $prefix))->handle($request, $next, $route);
        };
    }

    private function resolveKey(Request $request): string
    {
        return implode('|', array_filter([
            $this->prefix,
            $request->ip() ?? 'unknown',
            $request->path(),
        ], static fn (?string $value): bool => $value !== null && $value !== ''));
    }

    private function applyHeaders(Response $response, RateLimitResult $result): void
    {
        $response->setHeader('X-RateLimit-Limit', (string) $result->maxAttempts);
        $response->setHeader('X-RateLimit-Remaining', (string) $result->remaining);
        $response->setHeader('X-RateLimit-Reset', (string) $result->resetsAt);
    }
}
