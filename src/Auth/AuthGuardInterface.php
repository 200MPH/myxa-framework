<?php

declare(strict_types=1);

namespace Myxa\Auth;

use Myxa\Http\Request;

/**
 * Resolve the authenticated user for a request.
 */
interface AuthGuardInterface
{
    /**
     * Return the authenticated user for the request, if any.
     */
    public function user(Request $request): mixed;

    /**
     * Determine whether the request is authenticated.
     */
    public function check(Request $request): bool;
}
