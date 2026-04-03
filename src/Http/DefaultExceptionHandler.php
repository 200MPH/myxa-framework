<?php

declare(strict_types=1);

namespace Myxa\Http;

use Myxa\Routing\MethodNotAllowedException;
use Throwable;

final class DefaultExceptionHandler implements ExceptionHandlerInterface
{
    public function render(Throwable $exception, Request $request): Response
    {
        $status = ExceptionHttpMapper::statusCodeFor($exception);
        $message = $this->messageFor($exception, $status);
        $response = new Response();

        if ($request->expectsJson()) {
            $response->json([
                'error' => [
                    'type' => $this->errorTypeFor($exception),
                    'message' => $message,
                    'status' => $status,
                ],
            ], $status);
        } else {
            $response->text($message, $status);
        }

        if ($exception instanceof MethodNotAllowedException) {
            $response->setHeader('Allow', implode(', ', $exception->allowedMethods()));
        }

        return $response;
    }

    protected function messageFor(Throwable $exception, int $status): string
    {
        if ($status >= 500) {
            return 'Server Error';
        }

        return $exception->getMessage();
    }

    protected function errorTypeFor(Throwable $exception): string
    {
        $class = $exception::class;
        $position = strrpos($class, '\\');
        $base = $position === false ? $class : substr($class, $position + 1);
        $base = str_ends_with($base, 'Exception') ? substr($base, 0, -9) : $base;

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
    }
}
