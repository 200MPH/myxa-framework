<?php

declare(strict_types=1);

namespace Myxa\Auth;

use Myxa\Http\Request;

/**
 * Authenticate browser requests via a session cookie value.
 */
final class SessionGuard implements AuthGuardInterface
{
    public function __construct(
        private readonly SessionUserResolverInterface $resolver,
        private readonly string $cookieName = 'session',
    ) {
    }

    public function user(Request $request): mixed
    {
        $sessionId = $request->cookie($this->cookieName);
        if (!is_string($sessionId) || $sessionId === '') {
            return null;
        }

        return $this->resolver->resolve($sessionId, $request);
    }

    public function check(Request $request): bool
    {
        return $this->user($request) !== null;
    }
}
