<?php

declare(strict_types=1);

namespace Myxa\Redis\Connection;

interface RedisStoreInterface
{
    public function get(string $key): string|int|float|bool|null;

    public function set(string $key, string|int|float|bool|null $value): bool;

    public function delete(string $key): bool;

    public function has(string $key): bool;

    public function increment(string $key, int $by = 1): int;
}
