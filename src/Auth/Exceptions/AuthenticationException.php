<?php

declare(strict_types=1);

namespace Myxa\Auth\Exceptions;

use RuntimeException;

/**
 * Raised when a request is not authenticated for the selected guard.
 */
final class AuthenticationException extends RuntimeException
{
    public function __construct(
        private readonly string $guard = 'web',
        private readonly string $redirectTo = '/login',
        string $message = 'Unauthenticated.',
    ) {
        parent::__construct($message);
    }

    /**
     * Return the guard that failed authentication.
     */
    public function guard(): string
    {
        return $this->guard;
    }

    /**
     * Return the browser redirect path for interactive clients.
     */
    public function redirectTo(): string
    {
        return $this->redirectTo;
    }
}
