<?php

declare(strict_types=1);

namespace Myxa\Mongo\Connection;

use RuntimeException;

final class MongoConnection
{
    /** @var array<string, self> */
    private static array $registry = [];

    /** @var array<string, MongoCollectionInterface> */
    private array $collections = [];

    /**
     * @param array<string, MongoCollectionInterface> $collections
     */
    public function __construct(array $collections = [])
    {
        $this->collections = $collections;
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

    public function addCollection(string $name, MongoCollectionInterface $collection): self
    {
        $this->collections[$name] = $collection;

        return $this;
    }

    public function hasCollection(string $name): bool
    {
        return isset($this->collections[$name]);
    }

    public function collection(string $name): MongoCollectionInterface
    {
        $collection = $this->collections[$name] ?? null;
        if (!$collection instanceof MongoCollectionInterface) {
            throw new RuntimeException(sprintf('Collection "%s" is not registered on this Mongo connection.', $name));
        }

        return $collection;
    }
}
