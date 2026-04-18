<?php

declare(strict_types=1);

namespace Myxa\Queue;

/**
 * Optional queue-specific metadata exposed by a queued job.
 */
interface QueuedJobInterface extends JobInterface
{
    /**
     * Return the preferred queue name for this job.
     */
    public function queue(): ?string;

    /**
     * Return the initial enqueue delay in seconds.
     */
    public function delaySeconds(): int;

    /**
     * Return the maximum number of attempts allowed for this job.
     */
    public function maxAttempts(): int;
}
