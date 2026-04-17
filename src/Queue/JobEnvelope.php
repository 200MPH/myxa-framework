<?php

declare(strict_types=1);

namespace Myxa\Queue;

/**
 * Transport-neutral wrapper around a queued job and its delivery metadata.
 */
final readonly class JobEnvelope
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $id,
        public JobInterface $job,
        public ?string $queue = null,
        public int $attempts = 0,
        public array $context = [],
    ) {
    }
}
