<?php

declare(strict_types=1);

namespace Myxa\Cache\Store;

use Myxa\Cache\CacheStoreInterface;
use RuntimeException;

final class FileCacheStore implements CacheStoreInterface
{
    public function __construct(private readonly string $directory = 'data/cache')
    {
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
        $expiresAt = $ttl === null ? null : time() + max(0, $ttl);
        $path = $this->pathFor($key);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create cache directory "%s".', $directory));
        }

        $payload = json_encode([
            'expires_at' => $expiresAt,
            'value' => base64_encode(serialize($value)),
        ], JSON_THROW_ON_ERROR);

        return file_put_contents($path, $payload) !== false;
    }

    public function forget(string $key): bool
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return false;
        }

        return unlink($path);
    }

    public function has(string $key): bool
    {
        return $this->readPayload($key) !== null;
    }

    public function clear(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                return false;
            }
        }

        return true;
    }

    private function readPayload(string $key): ?array
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $payload = json_decode($contents, true);
        if (!is_array($payload) || !array_key_exists('value', $payload)) {
            $this->forget($key);

            return null;
        }

        $expiresAt = $payload['expires_at'] ?? null;
        if (is_int($expiresAt) && $expiresAt <= time()) {
            $this->forget($key);

            return null;
        }

        return [
            'value' => unserialize(base64_decode((string) $payload['value'], true) ?: '', ['allowed_classes' => true]),
        ];
    }

    private function pathFor(string $key): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . sha1($key) . '.cache';
    }
}
