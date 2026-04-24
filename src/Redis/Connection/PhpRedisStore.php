<?php

declare(strict_types=1);

namespace Myxa\Redis\Connection;

use RuntimeException;

final class PhpRedisStore implements RedisStoreInterface
{
    private ?\Redis $client = null;

    /**
     * @var (callable(): \Redis)|null
     */
    private mixed $clientFactory;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly float $timeout = 2.0,
        private readonly int $database = 0,
        private readonly ?string $password = null,
        ?callable $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory;
    }

    public function get(string $key): string|int|float|bool|null
    {
        $payload = $this->client()->get($key);
        if ($payload === false) {
            return null;
        }

        if (!is_string($payload)) {
            throw new RuntimeException(sprintf('Redis key "%s" returned a non-string payload.', $key));
        }

        return $this->decode($key, $payload);
    }

    public function set(string $key, string|int|float|bool|null $value): bool
    {
        return $this->client()->set($key, $this->encode($value));
    }

    public function delete(string $key): bool
    {
        return $this->client()->del($key) > 0;
    }

    public function has(string $key): bool
    {
        return $this->client()->exists($key) > 0;
    }

    public function increment(string $key, int $by = 1): int
    {
        $current = $this->get($key);
        if ($current !== null && !is_int($current)) {
            throw new RuntimeException(sprintf('Redis key "%s" does not contain an integer value.', $key));
        }

        $next = ($current ?? 0) + $by;
        $this->set($key, $next);

        return $next;
    }

    public function flush(): void
    {
        $this->client()->flushDB();
    }

    public function client(): \Redis
    {
        if (!class_exists(\Redis::class)) {
            throw new RuntimeException('The phpredis extension is not installed.');
        }

        if ($this->client instanceof \Redis) {
            return $this->client;
        }

        $client = $this->clientFactory !== null
            ? ($this->clientFactory)()
            : new \Redis();
        if (!$client instanceof \Redis) {
            throw new RuntimeException(sprintf('Redis client factory must return %s.', \Redis::class));
        }

        $connected = $client->connect($this->host, $this->port, $this->timeout);
        if ($connected !== true) {
            throw new RuntimeException(sprintf(
                'Unable to connect to Redis at %s:%d.',
                $this->host,
                $this->port,
            ));
        }

        if ($this->password !== null && $this->password !== '') {
            if ($client->auth($this->password) !== true) {
                throw new RuntimeException('Unable to authenticate with Redis.');
            }
        }

        if ($this->database !== 0 && $client->select($this->database) !== true) {
            throw new RuntimeException(sprintf('Unable to select Redis database %d.', $this->database));
        }

        $this->client = $client;

        return $this->client;
    }

    private function encode(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => json_encode(['type' => 'null', 'value' => null], JSON_THROW_ON_ERROR),
            is_bool($value) => json_encode(['type' => 'bool', 'value' => $value], JSON_THROW_ON_ERROR),
            is_int($value) => json_encode(['type' => 'int', 'value' => $value], JSON_THROW_ON_ERROR),
            is_float($value) => json_encode(['type' => 'float', 'value' => $value], JSON_THROW_ON_ERROR),
            default => json_encode(['type' => 'string', 'value' => $value], JSON_THROW_ON_ERROR),
        };
    }

    private function decode(string $key, string $payload): string|int|float|bool|null
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !array_key_exists('type', $decoded)) {
            throw new RuntimeException(sprintf('Redis key "%s" contains an invalid payload.', $key));
        }

        return match ($decoded['type']) {
            'null' => null,
            'bool' => (bool) ($decoded['value'] ?? false),
            'int' => (int) ($decoded['value'] ?? 0),
            'float' => (float) ($decoded['value'] ?? 0.0),
            'string' => (string) ($decoded['value'] ?? ''),
            default => throw new RuntimeException(sprintf('Redis key "%s" contains an unknown payload type.', $key)),
        };
    }
}
