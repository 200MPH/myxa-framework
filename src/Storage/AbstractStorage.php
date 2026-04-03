<?php

declare(strict_types=1);

namespace Myxa\Storage;

use InvalidArgumentException;

abstract class AbstractStorage implements StorageInterface
{
    /**
     * @param string $alias Public alias used to resolve this storage driver.
     */
    public function __construct(private readonly string $alias)
    {
    }

    /**
     * Return the storage alias registered for this driver.
     */
    final public function alias(): string
    {
        return $this->alias;
    }

    final protected function normalizeLocation(string $location): string
    {
        return StoragePath::normalizeLocation($location);
    }

    final protected function resolveName(string $location, array $options): string
    {
        $name = $options['name'] ?? basename($location);

        if (!is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('Stored file name must be a non-empty string.');
        }

        return $name;
    }

    final protected function resolveMimeType(array $options, ?string $fallback = null): ?string
    {
        $mimeType = $options['mime_type'] ?? $fallback;

        if ($mimeType === null) {
            return null;
        }

        if (!is_string($mimeType) || trim($mimeType) === '') {
            throw new InvalidArgumentException('Stored file MIME type must be a non-empty string.');
        }

        return $mimeType;
    }

    /**
     * @return array<string, mixed>
     */
    final protected function resolveMetadata(array $options): array
    {
        $metadata = $options['metadata'] ?? [];

        if (!is_array($metadata)) {
            throw new InvalidArgumentException('Stored file metadata must be an array.');
        }

        return $metadata;
    }
}
