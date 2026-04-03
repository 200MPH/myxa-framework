<?php

declare(strict_types=1);

namespace Myxa\Auth;

use Myxa\Http\Request;

/**
 * Default session resolver used until the application provides one.
 */
final class NullSessionUserResolver implements SessionUserResolverInterface
{
    public function resolve(string $sessionId, Request $request): mixed
    {
        return null;
    }
}
