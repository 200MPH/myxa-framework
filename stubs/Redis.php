<?php

declare(strict_types=1);

if (!class_exists('Redis')) {
    final class Redis
    {
        public function connect(string $host, int $port = 6379, float $timeout = 0.0): bool
        {
            return true;
        }

        public function auth(string $password): bool
        {
            return true;
        }

        public function select(int $database): bool
        {
            return true;
        }

        public function get(string $key): string|false
        {
            return false;
        }

        public function set(string $key, mixed $value): bool
        {
            return true;
        }

        public function del(string $key): int
        {
            return 0;
        }

        public function exists(string $key): int
        {
            return 0;
        }

        public function flushDB(): bool
        {
            return true;
        }
    }
}
