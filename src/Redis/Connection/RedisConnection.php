<?php

declare(strict_types=1);

namespace Myxa\Redis\Connection;

use RuntimeException;

final class RedisConnection
{
    /** @var array<string, self> */
    private static array $registry = [];

    public function __construct(private readonly RedisStoreInterface $store)
    {
    }

    public static function register(string $alias, self $connection, bool $replace = false): self
    {
        if (!$replace && isset(self::$registry[$alias])) {
            throw new RuntimeException(sprintf('Connection alias "%s" is already registered.', $alias));
        }

        self::$registry[$alias] = $connection;

        return $connection;
    }

    public static function get(string $alias): self
    {
        $connection = self::$registry[$alias] ?? null;
        if (!$connection instanceof self) {
            throw new RuntimeException(sprintf('Connection alias "%s" is not registered.', $alias));
        }

        return $connection;
    }

    public static function has(string $alias): bool
    {
        return isset(self::$registry[$alias]);
    }

    public static function unregister(string $alias): void
    {
        unset(self::$registry[$alias]);
    }

    public function store(): RedisStoreInterface
    {
        return $this->store;
    }

    public function getValue(string $key): string|int|float|bool|null
    {
        return $this->store->get($key);
    }

    public function setValue(string $key, string|int|float|bool|null $value): bool
    {
        return $this->store->set($key, $value);
    }

    public function delete(string $key): bool
    {
        return $this->store->delete($key);
    }

    public function hasKey(string $key): bool
    {
        return $this->store->has($key);
    }

    public function increment(string $key, int $by = 1): int
    {
        return $this->store->increment($key, $by);
    }
}
