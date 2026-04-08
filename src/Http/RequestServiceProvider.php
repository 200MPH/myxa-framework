<?php

declare(strict_types=1);

namespace Myxa\Http;

use Myxa\Application;
use Myxa\Support\Facades\Request as RequestFacade;
use Myxa\Support\ServiceProvider;

/**
 * Registers the current HTTP request and its facade binding.
 */
final class RequestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app()->singleton(Request::class, static fn (): Request => Request::capture());
        $this->app()->singleton('request', static fn (Application $app): Request => $app->make(Request::class));
    }

    public function boot(): void
    {
        RequestFacade::setRequest($this->app()->make(Request::class));
    }
}
