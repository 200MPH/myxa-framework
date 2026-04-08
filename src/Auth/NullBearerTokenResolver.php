<?php

declare(strict_types=1);

namespace Myxa\Auth;

use Myxa\Http\Request;

/**
 * Default bearer token resolver used until the application provides one.
 */
final class NullBearerTokenResolver implements BearerTokenResolverInterface
{
    public function resolve(string $token, Request $request): mixed
    {
        return null;
    }
}
