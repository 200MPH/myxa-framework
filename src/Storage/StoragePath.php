<?php

declare(strict_types=1);

namespace Myxa\Storage;

use InvalidArgumentException;

final class StoragePath
{
    /**
     * Normalize a relative storage path and reject traversal segments.
     */
    public static function normalizeLocation(string $location): string
    {
        $location = trim(str_replace('\\', '/', $location));
        $segments = array_values(array_filter(
            explode('/', trim($location, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            throw new InvalidArgumentException('File location cannot be empty.');
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('File location cannot contain traversal segments.');
            }
        }

        return implode('/', $segments);
    }
}
