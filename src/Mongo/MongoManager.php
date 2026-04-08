<?php

declare(strict_types=1);

namespace Myxa\Mongo;

use Closure;
use InvalidArgumentException;
use Myxa\Mongo\Connection\MongoCollectionInterface;
use Myxa\Mongo\Connection\MongoConnection;
use ReflectionFunction;
use RuntimeException;

final class MongoManager
{
    private const string DEFAULT_CONNECTION = 'main';

    /** @var array<string, MongoConnection> */
    private array $connections = [];

    /** @var array<string, Closure(self): MongoConnection> */
    private array $connectionFactories = [];

    public function __construct(private string $defaultConnection = self::DEFAULT_CONNECTION)
    {
        $this->defaultConnection = $this->normalizeConnectionName($this->defaultConnection);
    }

    /**
     * @param MongoConnection|callable(self): MongoConnection|callable(): MongoConnection $connection
     */
    public function addConnection(string $alias, MongoConnection|callable $connection, bool $replace = false): self
    {
        $alias = $this->normalizeConnectionName($alias);

        if (!$replace && ($this->hasManagedConnection($alias) || MongoConnection::has($alias))) {
            throw new RuntimeException(sprintf('Connection alias "%s" is already registered.', $alias));
        }

        unset($this->connections[$alias], $this->connectionFactories[$alias]);

        if ($connection instanceof MongoConnection) {
            $this->connections[$alias] = $connection;

            return $this;
        }

        $this->connectionFactories[$alias] = $this->normalizeConnectionFactory($alias, $connection);

        return $this;
    }

    public function hasConnection(string $alias): bool
    {
        $alias = $this->normalizeConnectionName($alias);

        return $this->hasManagedConnection($alias) || MongoConnection::has($alias);
    }

    public function connection(?string $alias = null): MongoConnection
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

        if (MongoConnection::has($resolvedAlias)) {
            return MongoConnection::get($resolvedAlias);
        }

        throw new RuntimeException(sprintf('Connection alias "%s" is not registered.', $resolvedAlias));
    }

    public function collection(string $name, ?string $connection = null): MongoCollectionInterface
    {
        return $this->connection($connection)->collection($name);
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
     * @param Closure(self): MongoConnection $factory
     */
    private function resolveConnectionFactory(string $alias, Closure $factory): MongoConnection
    {
        $connection = $factory($this);
        if (!$connection instanceof MongoConnection) {
            throw new RuntimeException(sprintf(
                'Connection factory for alias "%s" must return %s.',
                $alias,
                MongoConnection::class,
            ));
        }

        return $connection;
    }

    /**
     * @param callable(self): MongoConnection|callable(): MongoConnection $factory
     * @return Closure(self): MongoConnection
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
            return static fn (self $manager): MongoConnection => $factory();
        }

        return static fn (self $manager): MongoConnection => $factory($manager);
    }
}
