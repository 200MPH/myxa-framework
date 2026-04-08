<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use Myxa\Redis\Connection\RedisConnection;
use Myxa\Redis\RedisManager;

final class Redis
{
    private static ?RedisManager $manager = null;

    public static function setManager(RedisManager $manager): void
    {
        self::$manager = $manager;
    }

    public static function clearManager(): void
    {
        self::$manager = null;
    }

    public static function getManager(): RedisManager
    {
        return self::$manager ??= new RedisManager();
    }

    public static function connection(?string $alias = null): RedisConnection
    {
        return self::getManager()->connection($alias);
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        return self::getManager()->{$name}(...$arguments);
    }
}
