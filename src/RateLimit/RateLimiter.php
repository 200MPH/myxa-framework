<?php

declare(strict_types=1);

namespace Myxa\RateLimit;

use InvalidArgumentException;

/**
 * Consume and evaluate rate limit counters.
 */
final class RateLimiter
{
    public function __construct(private readonly RateLimiterStoreInterface $store)
    {
    }

    /**
     * Consume one attempt from the given bucket and return the updated state.
     */
    public function consume(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Rate limit max attempts must be at least 1.');
        }

        if ($decaySeconds < 1) {
            throw new InvalidArgumentException('Rate limit decay seconds must be at least 1.');
        }

        $now = time();
        $counter = $this->store->increment($key, $decaySeconds, $now);
        $tooManyAttempts = $counter->attempts > $maxAttempts;

        return new RateLimitResult(
            key: $key,
            attempts: $counter->attempts,
            maxAttempts: $maxAttempts,
            remaining: max(0, $maxAttempts - min($counter->attempts, $maxAttempts)),
            retryAfter: max(0, $counter->expiresAt - $now),
            resetsAt: $counter->expiresAt,
            tooManyAttempts: $tooManyAttempts,
        );
    }

    /**
     * Clear a rate limit bucket explicitly.
     */
    public function clear(string $key): void
    {
        $this->store->clear($key);
    }
}
