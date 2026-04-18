<?php

declare(strict_types=1);

namespace Myxa\Queue;

/**
 * Coordinates the queue worker lifecycle.
 */
interface WorkerInterface
{
    /**
     * Run the worker loop for the given queue and return an exit code.
     */
    public function run(?string $queue = null): int;

    /**
     * Process a single reserved message.
     */
    public function process(JobEnvelope $message): void;

    /**
     * Request a graceful stop.
     */
    public function stop(): void;
}
