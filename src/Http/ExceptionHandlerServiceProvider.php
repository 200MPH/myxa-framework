<?php

declare(strict_types=1);

namespace Myxa\Http;

use Myxa\Application;
use Myxa\Logging\LoggerInterface;
use Myxa\Support\ServiceProvider;

final class ExceptionHandlerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app()->singleton(
            ExceptionHandlerInterface::class,
            static fn (Application $app): ExceptionHandlerInterface => new DefaultExceptionHandler(
                $app->has(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
            ),
        );
        $this->app()->singleton(
            'exception.handler',
            static fn (Application $app): ExceptionHandlerInterface => $app->make(ExceptionHandlerInterface::class),
        );
    }
}
