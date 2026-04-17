<?php

declare(strict_types=1);

namespace Myxa\Queue;

use Closure;
use Myxa\Application;
use Myxa\Support\ServiceProvider;

final class QueueServiceProvider extends ServiceProvider
{
    public function __construct(
        private readonly QueueInterface|Closure|string|null $queue = null,
        private readonly WorkerInterface|Closure|string|null $worker = null,
        private readonly RetryPolicyInterface|Closure|string|null $retryPolicy = null,
    ) {
    }

    public function register(): void
    {
        $this->registerShared(QueueInterface::class, $this->queue);
        $this->registerShared(WorkerInterface::class, $this->worker);
        $this->registerShared(RetryPolicyInterface::class, $this->retryPolicy);

        if ($this->queue !== null) {
            $this->app()->singleton(
                'queue',
                static fn (Application $app): QueueInterface => $app->make(QueueInterface::class),
            );
        }

        if ($this->worker !== null) {
            $this->app()->singleton(
                'queue.worker',
                static fn (Application $app): WorkerInterface => $app->make(WorkerInterface::class),
            );
        }

        if ($this->retryPolicy !== null) {
            $this->app()->singleton(
                'queue.retry-policy',
                static fn (Application $app): RetryPolicyInterface => $app->make(RetryPolicyInterface::class),
            );
        }
    }

    private function registerShared(
        string $abstract,
        QueueInterface|WorkerInterface|RetryPolicyInterface|Closure|string|null $concrete,
    ): void
    {
        if ($concrete === null) {
            return;
        }

        if (is_object($concrete) && !$concrete instanceof Closure) {
            $this->app()->instance($abstract, $concrete);

            return;
        }

        $this->app()->singleton($abstract, $concrete);
    }
}
