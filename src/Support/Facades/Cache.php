<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use BadMethodCallException;
use Myxa\Cache\CacheManager;
use Myxa\Cache\CacheStoreInterface;

final class Cache
{
    private static ?CacheManager $manager = null;

    public static function setManager(CacheManager $manager): void
    {
        self::$manager = $manager;
    }

    public static function clearManager(): void
    {
        self::$manager = null;
    }

    public static function getManager(): CacheManager
    {
        return self::$manager ??= new CacheManager();
    }

    public static function store(?string $alias = null): CacheStoreInterface
    {
        return self::getManager()->store($alias);
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        if (!method_exists(self::getManager(), $name)) {
            throw new BadMethodCallException(sprintf('Cache facade method "%s" is not supported.', $name));
        }

        return self::getManager()->{$name}(...$arguments);
    }
}
