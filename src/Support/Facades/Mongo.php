<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use Myxa\Mongo\Connection\MongoCollectionInterface;
use Myxa\Mongo\Connection\MongoConnection;
use Myxa\Mongo\MongoManager;

final class Mongo
{
    private static ?MongoManager $manager = null;

    public static function setManager(MongoManager $manager): void
    {
        self::$manager = $manager;
    }

    public static function clearManager(): void
    {
        self::$manager = null;
    }

    public static function getManager(): MongoManager
    {
        return self::$manager ??= new MongoManager();
    }

    public static function connection(?string $alias = null): MongoConnection
    {
        return self::getManager()->connection($alias);
    }

    public static function collection(string $name, ?string $connection = null): MongoCollectionInterface
    {
        return self::getManager()->collection($name, $connection);
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        return self::getManager()->{$name}(...$arguments);
    }
}
