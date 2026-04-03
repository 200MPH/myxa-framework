<?php

declare(strict_types=1);

namespace Myxa\Logging;

use Myxa\Application;
use Myxa\Support\ServiceProvider;

final class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->app()->has(LoggerInterface::class)) {
            $this->app()->singleton(LoggerInterface::class, NullLogger::class);
        }

        $this->app()->singleton(
            'logger',
            static fn (Application $app): LoggerInterface => $app->make(LoggerInterface::class),
        );
    }
}
