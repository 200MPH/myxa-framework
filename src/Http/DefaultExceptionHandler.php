<?php

declare(strict_types=1);

namespace Myxa\Http;

use Myxa\Auth\AuthenticationException;
use Myxa\Logging\LogLevel;
use Myxa\Logging\LoggerInterface;
use Myxa\Logging\NullLogger;
use Myxa\RateLimit\TooManyRequestsException;
use Myxa\Routing\MethodNotAllowedException;
use Throwable;

final class DefaultExceptionHandler implements ExceptionHandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function report(Throwable $exception): void
    {
        $status = ExceptionHttpMapper::statusCodeFor($exception);

        $this->logger->log(
            $status >= 500 ? LogLevel::Error : LogLevel::Warning,
            $exception->getMessage(),
            [
                'exception' => $exception,
                'status' => $status,
                'type' => $this->errorTypeFor($exception),
            ],
        );
    }

    public function render(Throwable $exception, Request $request): Response
    {
        $status = ExceptionHttpMapper::statusCodeFor($exception);
        $message = $this->messageFor($exception, $status);
        $response = new Response();

        if ($exception instanceof AuthenticationException && !$request->expectsJson()) {
            return $response->redirect($exception->redirectTo());
        }

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

        if ($exception instanceof AuthenticationException && $exception->guard() === 'api') {
            $response->setHeader('WWW-Authenticate', 'Bearer');
        }

        if ($exception instanceof TooManyRequestsException) {
            $result = $exception->result();
            $response->setHeader('Retry-After', (string) $result->retryAfter);
            $response->setHeader('X-RateLimit-Limit', (string) $result->maxAttempts);
            $response->setHeader('X-RateLimit-Remaining', (string) $result->remaining);
            $response->setHeader('X-RateLimit-Reset', (string) $result->resetsAt);
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
        if ($exception instanceof AuthenticationException) {
            return 'unauthenticated';
        }

        $class = $exception::class;
        $position = strrpos($class, '\\');
        $base = $position === false ? $class : substr($class, $position + 1);
        $base = str_ends_with($base, 'Exception') ? substr($base, 0, -9) : $base;

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
    }
}
