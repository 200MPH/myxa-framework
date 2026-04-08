<?php

declare(strict_types=1);

namespace Myxa\Cache\Store;

use Myxa\Cache\CacheStoreInterface;
use Myxa\Redis\Connection\InMemoryRedisStore;
use Myxa\Redis\Connection\PhpRedisStore;
use Myxa\Redis\Connection\RedisConnection;
use RuntimeException;

final class RedisCacheStore implements CacheStoreInterface
{
    public function __construct(
        private readonly RedisConnection $connection,
        private readonly string $prefix = 'cache:',
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payload = $this->readPayload($key);
        if ($payload === null) {
            return $default;
        }

        return $payload['value'];
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $payload = json_encode([
            'expires_at' => $ttl === null ? null : time() + max(0, $ttl),
            'value' => base64_encode(serialize($value)),
        ], JSON_THROW_ON_ERROR);

        return $this->connection->setValue($this->key($key), $payload);
    }

    public function forget(string $key): bool
    {
        return $this->connection->delete($this->key($key));
    }

    public function has(string $key): bool
    {
        return $this->readPayload($key) !== null;
    }

    public function clear(): bool
    {
        $store = $this->connection->store();

        if ($store instanceof InMemoryRedisStore) {
            foreach (array_keys($store->all()) as $key) {
                if (str_starts_with($key, $this->prefix)) {
                    $store->delete($key);
                }
            }

            return true;
        }

        if ($store instanceof PhpRedisStore) {
            $keys = $store->client()->keys($this->prefix . '*');
            if (!is_array($keys) || $keys === []) {
                return true;
            }

            return $store->client()->del($keys) >= 0;
        }

        throw new RuntimeException('Redis cache clearing is not supported by this Redis store.');
    }

    private function readPayload(string $key): ?array
    {
        $payload = $this->connection->getValue($this->key($key));
        if ($payload === null) {
            return null;
        }

        if (!is_string($payload)) {
            throw new RuntimeException(sprintf('Cache key "%s" returned an invalid Redis payload.', $key));
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !array_key_exists('value', $decoded)) {
            $this->forget($key);

            return null;
        }

        $expiresAt = $decoded['expires_at'] ?? null;
        if (is_int($expiresAt) && $expiresAt <= time()) {
            $this->forget($key);

            return null;
        }

        return [
            'value' => unserialize(base64_decode((string) $decoded['value'], true) ?: '', ['allowed_classes' => true]),
        ];
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }
}
