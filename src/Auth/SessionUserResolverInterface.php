<?php

declare(strict_types=1);

namespace Myxa\Auth;

use Myxa\Http\Request;

/**
 * Resolve an application user from a session identifier.
 */
interface SessionUserResolverInterface
{
    /**
     * Return the authenticated user for the given session ID, if any.
     */
    public function resolve(string $sessionId, Request $request): mixed;
}
