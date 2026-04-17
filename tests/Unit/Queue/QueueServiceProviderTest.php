<?php

declare(strict_types=1);

namespace Test\Unit\Queue;

use Myxa\Application;
use Myxa\Queue\JobEnvelope;
use Myxa\Queue\JobInterface;
use Myxa\Queue\QueueInterface;
use Myxa\Queue\QueueServiceProvider;
use Myxa\Queue\RetryPolicyInterface;
use Myxa\Queue\WorkerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(QueueServiceProvider::class)]
final class QueueServiceProviderTest extends TestCase
{
    public function testProviderCanRegisterConcreteInstances(): void
    {
        $queue = new QueueServiceProviderTestQueue();
        $retryPolicy = new QueueServiceProviderTestRetryPolicy();
        $worker = new QueueServiceProviderTestWorker($queue, $retryPolicy);

        $app = new Application();
        $app->register(new QueueServiceProvider(
            queue: $queue,
            worker: $worker,
            retryPolicy: $retryPolicy,
        ));
        $app->boot();

        self::assertSame($queue, $app->make(QueueInterface::class));
        self::assertSame($worker, $app->make(WorkerInterface::class));
        self::assertSame($retryPolicy, $app->make(RetryPolicyInterface::class));
        self::assertSame($queue, $app->make('queue'));
        self::assertSame($worker, $app->make('queue.worker'));
        self::assertSame($retryPolicy, $app->make('queue.retry-policy'));
    }

    public function testProviderSkipsBindingsWhenNoImplementationsAreProvided(): void
    {
        $app = new Application();
        $app->register(new QueueServiceProvider());
        $app->boot();

        self::assertFalse($app->has('queue'));
        self::assertFalse($app->has('queue.worker'));
        self::assertFalse($app->has('queue.retry-policy'));
    }

    public function testProviderRegistersConfiguredQueueBindingsAndAliases(): void
    {
        $app = new Application();
        $app->register(new QueueServiceProvider(
            queue: QueueServiceProviderTestQueue::class,
            worker: QueueServiceProviderTestWorker::class,
            retryPolicy: QueueServiceProviderTestRetryPolicy::class,
        ));
        $app->boot();

        $queue = $app->make(QueueInterface::class);
        $worker = $app->make(WorkerInterface::class);
        $retryPolicy = $app->make(RetryPolicyInterface::class);

        self::assertInstanceOf(QueueServiceProviderTestQueue::class, $queue);
        self::assertInstanceOf(QueueServiceProviderTestWorker::class, $worker);
        self::assertInstanceOf(QueueServiceProviderTestRetryPolicy::class, $retryPolicy);
        self::assertSame($queue, $app->make('queue'));
        self::assertSame($worker, $app->make('queue.worker'));
        self::assertSame($retryPolicy, $app->make('queue.retry-policy'));
        self::assertSame($queue, $worker->queue);
        self::assertSame($retryPolicy, $worker->retryPolicy);
    }
}

final class QueueServiceProviderTestQueue implements QueueInterface
{
    public function push(JobInterface $job, array $context = [], ?string $queue = null): string
    {
        return 'job-1';
    }

    public function pop(?string $queue = null): ?JobEnvelope
    {
        return null;
    }

    public function ack(JobEnvelope $message): void
    {
    }

    public function release(JobEnvelope $message, int $delaySeconds = 0): void
    {
    }

    public function fail(JobEnvelope $message, ?Throwable $error = null): void
    {
    }
}

final readonly class QueueServiceProviderTestWorker implements WorkerInterface
{
    public function __construct(
        public QueueInterface $queue,
        public RetryPolicyInterface $retryPolicy,
    ) {
    }

    public function run(?string $queue = null): int
    {
        return 0;
    }

    public function process(JobEnvelope $message): void
    {
    }

    public function stop(): void
    {
    }
}

final class QueueServiceProviderTestRetryPolicy implements RetryPolicyInterface
{
    public function shouldRetry(JobEnvelope $message, Throwable $error): bool
    {
        return false;
    }

    public function delaySeconds(JobEnvelope $message, Throwable $error): int
    {
        return 0;
    }
}
