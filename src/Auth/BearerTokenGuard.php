<?php

declare(strict_types=1);

namespace Myxa\Auth;

use Myxa\Http\Request;

/**
 * Authenticate API requests via the Authorization bearer token.
 */
final class BearerTokenGuard implements AuthGuardInterface
{
    public function __construct(private readonly BearerTokenResolverInterface $resolver)
    {
    }

    public function user(Request $request): mixed
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return null;
        }

        return $this->resolver->resolve($token, $request);
    }

    public function check(Request $request): bool
    {
        return $this->user($request) !== null;
    }
}
