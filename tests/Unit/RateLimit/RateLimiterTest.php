<?php

declare(strict_types=1);

namespace Test\Unit\RateLimit;

use Myxa\RateLimit\FileRateLimiterStore;
use Myxa\RateLimit\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileRateLimiterStore::class)]
#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/myxa-rate-limit-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testRateLimiterConsumesAttemptsAndBlocksAfterLimit(): void
    {
        $limiter = new RateLimiter(new FileRateLimiterStore($this->directory));

        $first = $limiter->consume('ip|/api/posts', 2, 60);
        $second = $limiter->consume('ip|/api/posts', 2, 60);
        $third = $limiter->consume('ip|/api/posts', 2, 60);

        self::assertSame(1, $first->attempts);
        self::assertSame(1, $first->remaining);
        self::assertFalse($first->tooManyAttempts);
        self::assertSame(2, $second->attempts);
        self::assertSame(0, $second->remaining);
        self::assertFalse($second->tooManyAttempts);
        self::assertSame(3, $third->attempts);
        self::assertSame(0, $third->remaining);
        self::assertTrue($third->tooManyAttempts);
        self::assertGreaterThan(0, $third->retryAfter);
    }

    public function testRateLimiterCanClearBuckets(): void
    {
        $limiter = new RateLimiter(new FileRateLimiterStore($this->directory));
        $limiter->consume('ip|/api/posts', 1, 60);
        $limiter->clear('ip|/api/posts');

        $result = $limiter->consume('ip|/api/posts', 1, 60);

        self::assertSame(1, $result->attempts);
        self::assertFalse($result->tooManyAttempts);
    }
}
