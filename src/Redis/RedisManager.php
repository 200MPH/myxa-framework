<?php

declare(strict_types=1);

namespace Myxa\Redis;

use Closure;
use InvalidArgumentException;
use Myxa\Redis\Connection\RedisConnection;
use ReflectionFunction;
use RuntimeException;

final class RedisManager
{
    private const string DEFAULT_CONNECTION = 'main';

    /** @var array<string, RedisConnection> */
    private array $connections = [];

    /** @var array<string, Closure(self): RedisConnection> */
    private array $connectionFactories = [];

    /**
     * @param RedisConnection|(callable(self): RedisConnection)|(callable(): RedisConnection)|null $connection
     */
    public function __construct(
        private string $defaultConnection = self::DEFAULT_CONNECTION,
        RedisConnection|callable|null $connection = null,
    ) {
        $this->defaultConnection = $this->normalizeConnectionName($this->defaultConnection);

        if ($connection !== null) {
            $this->addConnection($this->defaultConnection, $connection);
        }
    }

    /**
     * @param RedisConnection|(callable(self): RedisConnection)|(callable(): RedisConnection) $connection
     */
    public function addConnection(string $alias, RedisConnection|callable $connection, bool $replace = false): self
    {
        $alias = $this->normalizeConnectionName($alias);

        if (!$replace && ($this->hasManagedConnection($alias) || RedisConnection::has($alias))) {
            throw new RuntimeException(sprintf('Connection alias "%s" is already registered.', $alias));
        }

        unset($this->connections[$alias], $this->connectionFactories[$alias]);

        if ($connection instanceof RedisConnection) {
            $this->connections[$alias] = $connection;

            return $this;
        }

        $this->connectionFactories[$alias] = $this->normalizeConnectionFactory($alias, $connection);

        return $this;
    }

    public function hasConnection(string $alias): bool
    {
        $alias = $this->normalizeConnectionName($alias);

        return $this->hasManagedConnection($alias) || RedisConnection::has($alias);
    }

    public function connection(?string $alias = null): RedisConnection
    {
        $resolvedAlias = $this->resolveConnectionName($alias);

        if (isset($this->connections[$resolvedAlias])) {
            return $this->connections[$resolvedAlias];
        }

        if (isset($this->connectionFactories[$resolvedAlias])) {
            $this->connections[$resolvedAlias] = $this->resolveConnectionFactory(
                $resolvedAlias,
                $this->connectionFactories[$resolvedAlias],
            );

            return $this->connections[$resolvedAlias];
        }

        if (RedisConnection::has($resolvedAlias)) {
            return RedisConnection::get($resolvedAlias);
        }

        throw new RuntimeException(sprintf('Connection alias "%s" is not registered.', $resolvedAlias));
    }

    public function get(string $key, ?string $connection = null): string|int|float|bool|null
    {
        return $this->connection($connection)->getValue($key);
    }

    public function set(string $key, string|int|float|bool|null $value, ?string $connection = null): bool
    {
        return $this->connection($connection)->setValue($key, $value);
    }

    public function delete(string $key, ?string $connection = null): bool
    {
        return $this->connection($connection)->delete($key);
    }

    public function has(string $key, ?string $connection = null): bool
    {
        return $this->connection($connection)->hasKey($key);
    }

    public function increment(string $key, int $by = 1, ?string $connection = null): int
    {
        return $this->connection($connection)->increment($key, $by);
    }

    public function getDefaultConnection(): string
    {
        return $this->defaultConnection;
    }

    public function setDefaultConnection(string $alias): self
    {
        $this->defaultConnection = $this->normalizeConnectionName($alias);

        return $this;
    }

    private function hasManagedConnection(string $alias): bool
    {
        return isset($this->connections[$alias]) || isset($this->connectionFactories[$alias]);
    }

    private function resolveConnectionName(?string $alias): string
    {
        return $alias === null ? $this->defaultConnection : $this->normalizeConnectionName($alias);
    }

    private function normalizeConnectionName(string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new InvalidArgumentException('Connection alias cannot be empty.');
        }

        return $alias;
    }

    /**
     * @param Closure(self): RedisConnection $factory
     */
    private function resolveConnectionFactory(string $alias, Closure $factory): RedisConnection
    {
        $connection = $factory($this);
        if (!$connection instanceof RedisConnection) {
            throw new RuntimeException(sprintf(
                'Connection factory for alias "%s" must return %s.',
                $alias,
                RedisConnection::class,
            ));
        }

        return $connection;
    }

    /**
     * @param (callable(self): RedisConnection)|(callable(): RedisConnection) $factory
     * @return Closure(self): RedisConnection
     */
    private function normalizeConnectionFactory(string $alias, callable $factory): Closure
    {
        $reflection = new ReflectionFunction(Closure::fromCallable($factory));
        $parameterCount = $reflection->getNumberOfParameters();

        if ($parameterCount > 1) {
            throw new InvalidArgumentException(sprintf(
                'Connection factory for alias "%s" must accept zero or one parameter.',
                $alias,
            ));
        }

        if ($parameterCount === 0) {
            return static fn (self $manager): RedisConnection => $factory();
        }

        return static fn (self $manager): RedisConnection => $factory($manager);
    }
}
