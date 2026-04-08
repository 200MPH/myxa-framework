<?php

declare(strict_types=1);

namespace Myxa\Http;

use Throwable;

interface ExceptionHandlerInterface
{
    public function report(Throwable $exception): void;

    public function render(Throwable $exception, Request $request): Response;
}
