<?php

declare(strict_types=1);

namespace Myxa\Queue;

/**
 * Executable unit of work handled by a queue worker.
 */
interface JobInterface
{
    /**
     * Execute the job.
     */
    public function handle(): void;
}
