<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use BadMethodCallException;
use Myxa\Validation\ValidationManager;

final class Validator
{
    private static ?ValidationManager $manager = null;

    /**
     * Set the shared validation manager used by the facade.
     */
    public static function setManager(ValidationManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * Clear the shared validation manager.
     */
    public static function clearManager(): void
    {
        self::$manager = null;
    }

    /**
     * Return the shared validation manager.
     */
    public static function getManager(): ValidationManager
    {
        return self::$manager ??= new ValidationManager();
    }

    /**
     * Forward unknown static calls to the validation manager.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if (!method_exists(self::getManager(), $name)) {
            throw new BadMethodCallException(sprintf('Validator facade method "%s" is not supported.', $name));
        }

        return self::getManager()->{$name}(...$arguments);
    }
}
