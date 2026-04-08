<?php

declare(strict_types=1);

namespace Myxa\Routing;

use Myxa\Application;
use Myxa\Support\Facades\Route as RouteFacade;
use Myxa\Support\ServiceProvider;

/**
 * Registers the framework router and its facade binding.
 */
final class RouteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app()->singleton(Router::class, static fn (Application $app): Router => new Router($app));
        $this->app()->singleton('router', static fn (Application $app): Router => $app->make(Router::class));
    }

    public function boot(): void
    {
        RouteFacade::setRouter($this->app()->make(Router::class));
    }
}
