<?php

declare(strict_types=1);

namespace Myxa\Http;

use Myxa\Support\Facades\Response as ResponseFacade;
use Myxa\Support\ServiceProvider;

/**
 * Registers the current HTTP response and its facade binding.
 */
final class ResponseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app()->singleton(Response::class, static fn (): Response => new Response());
    }

    public function boot(): void
    {
        ResponseFacade::setResponse($this->app()->make(Response::class));
    }
}
