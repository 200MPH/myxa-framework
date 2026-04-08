<?php

declare(strict_types=1);

namespace Myxa\Redis\Connection;

use RuntimeException;

final class InMemoryRedisStore implements RedisStoreInterface
{
    /** @var array<string, string|int|float|bool|null> */
    private array $values = [];

    public function get(string $key): string|int|float|bool|null
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string|int|float|bool|null $value): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        if (!array_key_exists($key, $this->values)) {
            return false;
        }

        unset($this->values[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function increment(string $key, int $by = 1): int
    {
        $current = $this->values[$key] ?? 0;
        if (!is_int($current)) {
            throw new RuntimeException(sprintf('Redis key "%s" does not contain an integer value.', $key));
        }

        $current += $by;
        $this->values[$key] = $current;

        return $current;
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    public function all(): array
    {
        return $this->values;
    }
}
