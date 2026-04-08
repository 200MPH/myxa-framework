<?php

declare(strict_types=1);

namespace Myxa\RateLimit;

/**
 * Persisted counter state for a single rate limit key.
 */
final readonly class RateLimitCounter
{
    public function __construct(
        public int $attempts,
        public int $expiresAt,
    ) {
    }
}
