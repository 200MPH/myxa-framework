# Queue

The queue layer provides transport-neutral contracts for background job processing.

It is intentionally small: Myxa does not ship a built-in Redis, RabbitMQ, database, or SQS adapter here. Instead, this package gives you the interfaces and message envelope needed to build or plug in those adapters cleanly.

## What It Provides

- `QueueInterface` for pushing, reserving, acknowledging, releasing, and failing jobs
- `JobInterface` for executable units of work
- `QueuedJobInterface` for optional job-level queue metadata
- `WorkerInterface` for worker lifecycle coordination
- `RetryPolicyInterface` for retry decisions and backoff timing
- `JobEnvelope` as the queue message wrapper passed between queue implementations and workers
- `QueueServiceProvider` for binding your own implementations into the application container

## Contract Overview

### `JobInterface`

Represents the actual work to perform.

```php
use Myxa\Queue\JobInterface;

final class SendWelcomeEmailJob implements JobInterface
{
    public function __construct(private int $userId)
    {
    }

    public function handle(): void
    {
        // Load the user and send the email.
    }
}
```

### `QueuedJobInterface`

Adds queue-specific metadata to a job when the worker or adapter needs it.

```php
use Myxa\Queue\QueuedJobInterface;

final class GenerateInvoicePdfJob implements QueuedJobInterface
{
    public function __construct(private int $invoiceId)
    {
    }

    public function handle(): void
    {
        // Build and store the PDF.
    }

    public function queue(): ?string
    {
        return 'documents';
    }

    public function delaySeconds(): int
    {
        return 0;
    }

    public function maxAttempts(): int
    {
        return 3;
    }
}
```

### `JobEnvelope`

Represents the queued message being handed to a worker. It wraps the job plus delivery metadata.

```php
use Myxa\Queue\JobEnvelope;

$message = new JobEnvelope(
    id: 'msg-123',
    job: new SendWelcomeEmailJob(42),
    queue: 'emails',
    attempts: 1,
    context: ['trace_id' => 'signup-42'],
);
```

### `QueueInterface`

Represents the queue transport itself.

```php
use Myxa\Queue\JobEnvelope;
use Myxa\Queue\JobInterface;
use Myxa\Queue\QueueInterface;

final class InMemoryQueue implements QueueInterface
{
    /** @var list<JobEnvelope> */
    private array $messages = [];

    public function push(JobInterface $job, array $context = [], ?string $queue = null): string
    {
        $id = 'job-' . (count($this->messages) + 1);

        $this->messages[] = new JobEnvelope(
            id: $id,
            job: $job,
            queue: $queue,
            context: $context,
        );

        return $id;
    }

    public function pop(?string $queue = null): ?JobEnvelope
    {
        foreach ($this->messages as $index => $message) {
            if ($queue !== null && $message->queue !== $queue) {
                continue;
            }

            unset($this->messages[$index]);

            return $message;
        }

        return null;
    }

    public function ack(JobEnvelope $message): void
    {
    }

    public function release(JobEnvelope $message, int $delaySeconds = 0): void
    {
        $this->messages[] = new JobEnvelope(
            id: $message->id,
            job: $message->job,
            queue: $message->queue,
            attempts: $message->attempts + 1,
            context: $message->context,
        );
    }

    public function fail(JobEnvelope $message, ?\Throwable $error = null): void
    {
    }
}
```

### `RetryPolicyInterface`

Keeps retry decisions separate from the worker loop.

```php
use Myxa\Queue\JobEnvelope;
use Myxa\Queue\RetryPolicyInterface;

final class SimpleRetryPolicy implements RetryPolicyInterface
{
    public function shouldRetry(JobEnvelope $message, \Throwable $error): bool
    {
        $maxAttempts = $message->job instanceof \Myxa\Queue\QueuedJobInterface
            ? $message->job->maxAttempts()
            : 3;

        return $message->attempts < $maxAttempts;
    }

    public function delaySeconds(JobEnvelope $message, \Throwable $error): int
    {
        return ($message->attempts + 1) * 30;
    }
}
```

### `WorkerInterface`

Represents the process that consumes messages from the queue and executes them.

```php
use Myxa\Queue\JobEnvelope;
use Myxa\Queue\QueueInterface;
use Myxa\Queue\RetryPolicyInterface;
use Myxa\Queue\WorkerInterface;

final class SimpleWorker implements WorkerInterface
{
    private bool $running = true;

    public function __construct(
        private QueueInterface $queue,
        private RetryPolicyInterface $retryPolicy,
    ) {
    }

    public function run(?string $queue = null): int
    {
        while ($this->running) {
            $message = $this->queue->pop($queue);

            if ($message === null) {
                break;
            }

            $this->process($message);
        }

        return 0;
    }

    public function process(JobEnvelope $message): void
    {
        try {
            $message->job->handle();
            $this->queue->ack($message);
        } catch (\Throwable $error) {
            if ($this->retryPolicy->shouldRetry($message, $error)) {
                $this->queue->release($message, $this->retryPolicy->delaySeconds($message, $error));

                return;
            }

            $this->queue->fail($message, $error);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
```

## Real-Life Example

This is the shape of a typical signup email flow:

1. A controller or service creates `SendWelcomeEmailJob`.
2. `QueueInterface::push()` stores the job in your queue backend.
3. A worker reserves the next `JobEnvelope`.
4. The worker runs `$message->job->handle()`.
5. On success, the worker calls `ack()`.
6. On failure, the worker asks `RetryPolicyInterface` whether to retry.
7. The queue either gets `release()` with a delay or `fail()`.

## Register Implementations With the Service Provider

`QueueServiceProvider` is optional. It is mainly a convenience wrapper around container bindings.

```php
use Myxa\Application;
use Myxa\Queue\QueueInterface;
use Myxa\Queue\QueueServiceProvider;
use Myxa\Queue\RetryPolicyInterface;
use Myxa\Queue\WorkerInterface;

$app = new Application();

$app->register(new QueueServiceProvider(
    queue: InMemoryQueue::class,
    worker: SimpleWorker::class,
    retryPolicy: SimpleRetryPolicy::class,
));

$app->boot();

$queue = $app->make(QueueInterface::class);
$worker = $app->make(WorkerInterface::class);
$retry = $app->make(RetryPolicyInterface::class);
```

The provider also registers convenience aliases when you supply those implementations:

- `'queue'`
- `'queue.worker'`
- `'queue.retry-policy'`

## Push and Process a Job

```php
$queue->push(
    new SendWelcomeEmailJob(42),
    context: ['trace_id' => 'signup-42'],
    queue: 'emails',
);

$worker->run('emails');
```

## Notes

- the queue layer defines contracts only, not a built-in backend
- these contracts can support Redis, RabbitMQ, database-backed queues, SQS, or sync/in-memory adapters
- `JobEnvelope` is the transport-neutral queue message wrapper between your queue implementation and your worker
- `QueuedJobInterface` is optional; plain `JobInterface` jobs can still be queued
- `QueueServiceProvider` only registers implementations you explicitly provide
