<?php

declare(strict_types=1);

namespace Myxa\RateLimit;

/**
 * Outcome of consuming a rate limit slot.
 */
final readonly class RateLimitResult
{
    public function __construct(
        public string $key,
        public int $attempts,
        public int $maxAttempts,
        public int $remaining,
        public int $retryAfter,
        public int $resetsAt,
        public bool $tooManyAttempts,
    ) {
    }
}
