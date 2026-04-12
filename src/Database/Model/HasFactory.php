<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use Myxa\Database\DatabaseManager;
use Myxa\Database\Factory\Factory;

trait HasFactory
{
    /**
     * Return a factory instance for the model.
     */
    public static function factory(?DatabaseManager $manager = null): Factory
    {
        $factory = static::newFactory();

        return $manager === null ? $factory : $factory->withManager($manager);
    }

    /**
     * Build the base factory for the model.
     */
    abstract protected static function newFactory(): Factory;
}
