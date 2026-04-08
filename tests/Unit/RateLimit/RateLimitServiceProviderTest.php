<?php

declare(strict_types=1);

namespace Test\Unit\RateLimit;

use Myxa\Application;
use Myxa\RateLimit\FileRateLimiterStore;
use Myxa\RateLimit\RateLimiter;
use Myxa\RateLimit\RateLimiterStoreInterface;
use Myxa\RateLimit\RateLimitServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimitServiceProvider::class)]
final class RateLimitServiceProviderTest extends TestCase
{
    public function testProviderRegistersDefaultLimiterAndStore(): void
    {
        $app = new Application();
        $app->register(RateLimitServiceProvider::class);
        $app->boot();

        self::assertInstanceOf(RateLimiter::class, $app->make(RateLimiter::class));
        self::assertInstanceOf(RateLimiter::class, $app->make('rate.limiter'));
        self::assertInstanceOf(FileRateLimiterStore::class, $app->make(RateLimiterStoreInterface::class));
    }
}
