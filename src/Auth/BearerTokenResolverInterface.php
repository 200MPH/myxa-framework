<?php

declare(strict_types=1);

namespace Myxa\Auth;

use Myxa\Http\Request;

/**
 * Resolve an application user from a bearer token string.
 */
interface BearerTokenResolverInterface
{
    /**
     * Return the authenticated user for the given token, if any.
     */
    public function resolve(string $token, Request $request): mixed;
}
