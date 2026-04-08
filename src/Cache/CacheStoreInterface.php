<?php

declare(strict_types=1);

namespace Myxa\Cache;

interface CacheStoreInterface
{
    /**
     * Get a cached value by key.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store a value in the cache.
     *
     * @param int|null $ttl Time to live in seconds. `null` stores the value until it is removed manually.
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Remove a cached value by key.
     */
    public function forget(string $key): bool;

    /**
     * Determine whether a non-expired cache entry exists for the given key.
     */
    public function has(string $key): bool;

    /**
     * Remove all cached values from the store.
     */
    public function clear(): bool;
}
