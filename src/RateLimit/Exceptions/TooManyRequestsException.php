<?php

declare(strict_types=1);

namespace Myxa\RateLimit\Exceptions;

use Myxa\RateLimit\RateLimitResult;
use RuntimeException;

/**
 * Raised when a request exceeds its configured rate limit.
 */
final class TooManyRequestsException extends RuntimeException
{
    public function __construct(
        private readonly RateLimitResult $result,
        string $message = 'Too Many Requests.',
    ) {
        parent::__construct($message);
    }

    /**
     * Return the limiter decision that caused the exception.
     */
    public function result(): RateLimitResult
    {
        return $this->result;
    }
}
