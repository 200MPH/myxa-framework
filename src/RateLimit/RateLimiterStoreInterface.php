<?php

declare(strict_types=1);

namespace Myxa\RateLimit;

/**
 * Persist rate limit counters between requests.
 */
interface RateLimiterStoreInterface
{
    /**
     * Increment the counter for a key and return the updated state.
     */
    public function increment(string $key, int $decaySeconds, int $now): RateLimitCounter;

    /**
     * Remove a persisted counter when present.
     */
    public function clear(string $key): void;
}
