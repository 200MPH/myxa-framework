<?php

declare(strict_types=1);

namespace Test\Unit\Queue;

use Myxa\Queue\JobEnvelope;
use Myxa\Queue\JobInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobEnvelope::class)]
final class JobEnvelopeTest extends TestCase
{
    public function testEnvelopeStoresJobMetadata(): void
    {
        $job = new class implements JobInterface
        {
            public function handle(): void
            {
            }
        };

        $envelope = new JobEnvelope(
            id: 'job-123',
            job: $job,
            queue: 'emails',
            attempts: 2,
            context: ['trace_id' => 'abc-123'],
        );

        self::assertSame('job-123', $envelope->id);
        self::assertSame($job, $envelope->job);
        self::assertSame('emails', $envelope->queue);
        self::assertSame(2, $envelope->attempts);
        self::assertSame(['trace_id' => 'abc-123'], $envelope->context);
    }
}
