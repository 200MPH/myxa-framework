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

    /** @var (callable(string): MongoCollectionInterface)|null */
    private mixed $collectionFactory;

    /**
     * @param array<string, MongoCollectionInterface> $collections
     * @param (callable(string): MongoCollectionInterface)|null $collectionFactory
     */
    public function __construct(array $collections = [], ?callable $collectionFactory = null)
    {
        $this->collections = $collections;
        $this->collectionFactory = $collectionFactory;
    }

    public static function fromUri(
        string $uri,
        string $database,
        array $uriOptions = [],
        array $driverOptions = [],
    ): self {
        $clientClass = 'MongoDB\\Client';
        if (!class_exists($clientClass)) {
            throw new RuntimeException('The mongodb/mongodb package is not installed.');
        }

        $client = new $clientClass($uri, $uriOptions, $driverOptions);
        $database = trim($database);
        if ($database === '') {
            throw new RuntimeException('Mongo database name cannot be empty.');
        }

        return self::fromDatabase($client->selectDatabase($database));
    }

    public static function fromDatabase(object $database): self
    {
        if (!method_exists($database, 'selectCollection')) {
            throw new RuntimeException('Mongo database object must provide selectCollection().');
        }

        return new self(collectionFactory: static fn (string $name): MongoCollectionInterface => new MongoDbCollection(
            $database->selectCollection($name),
        ));
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
        if ($collection instanceof MongoCollectionInterface) {
            return $collection;
        }

        if ($this->collectionFactory !== null) {
            $collection = ($this->collectionFactory)($name);
            if (!$collection instanceof MongoCollectionInterface) {
                throw new RuntimeException(sprintf(
                    'Collection factory for "%s" must return %s.',
                    $name,
                    MongoCollectionInterface::class,
                ));
            }

            $this->collections[$name] = $collection;

            return $collection;
        }

        if (!$collection instanceof MongoCollectionInterface) {
            throw new RuntimeException(sprintf('Collection "%s" is not registered on this Mongo connection.', $name));
        }

        return $collection;
    }
}
