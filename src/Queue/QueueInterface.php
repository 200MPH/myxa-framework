<?php

declare(strict_types=1);

namespace Myxa\Queue;

use Throwable;

/**
 * Transport-neutral queue contract for pushing, reserving, and settling jobs.
 */
interface QueueInterface
{
    /**
     * Push a job onto the queue and return the backend message identifier.
     *
     * @param array<string, mixed> $context
     */
    public function push(JobInterface $job, array $context = [], ?string $queue = null): string;

    /**
     * Reserve the next available queued message, if one exists.
     */
    public function pop(?string $queue = null): ?JobEnvelope;

    /**
     * Mark a previously reserved message as completed.
     */
    public function ack(JobEnvelope $message): void;

    /**
     * Return a reserved message to the queue, optionally delayed.
     */
    public function release(JobEnvelope $message, int $delaySeconds = 0): void;

    /**
     * Mark a message as failed and optionally attach the underlying error.
     */
    public function fail(JobEnvelope $message, ?Throwable $error = null): void;
}
