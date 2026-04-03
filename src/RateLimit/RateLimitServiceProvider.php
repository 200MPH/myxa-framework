<?php

declare(strict_types=1);

namespace Myxa\RateLimit;

use Myxa\Application;
use Myxa\Support\ServiceProvider;

/**
 * Register the default rate limiter and its persistent store.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->app()->has(RateLimiterStoreInterface::class)) {
            $this->app()->singleton(
                RateLimiterStoreInterface::class,
                static fn (): RateLimiterStoreInterface => new FileRateLimiterStore(sys_get_temp_dir() . '/myxa-rate-limit'),
            );
        }

        $this->app()->singleton(
            RateLimiter::class,
            static fn (Application $app): RateLimiter => new RateLimiter($app->make(RateLimiterStoreInterface::class)),
        );
        $this->app()->singleton('rate.limiter', static fn (Application $app): RateLimiter => $app->make(RateLimiter::class));
    }
}
