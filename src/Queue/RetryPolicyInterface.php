<?php

declare(strict_types=1);

namespace Myxa\Queue;

use Throwable;

/**
 * Decides whether a failed message should be retried and when.
 */
interface RetryPolicyInterface
{
    /**
     * Determine whether the failed message should be retried.
     */
    public function shouldRetry(JobEnvelope $message, Throwable $error): bool;

    /**
     * Return the delay before the next attempt in seconds.
     */
    public function delaySeconds(JobEnvelope $message, Throwable $error): int;
}
