<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use BadMethodCallException;
use Myxa\Storage\StorageInterface;
use Myxa\Storage\StorageManager;
use Myxa\Storage\StoredFile;
use Myxa\Storage\UploadedFile;

/**
 * Small static storage facade inspired by the DB helper.
 */
final class Storage
{
    private static ?StorageManager $manager = null;

    /**
     * Point the facade at the active storage manager.
     */
    public static function setManager(StorageManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * Clear the current storage manager instance.
     */
    public static function clearManager(): void
    {
        self::$manager = null;
    }

    /**
     * Return the active storage manager.
     */
    public static function getManager(): StorageManager
    {
        return self::$manager ??= new StorageManager();
    }

    /**
     * Register a storage driver under an alias.
     *
     * @param StorageInterface|callable $storage
     */
    public static function addStorage(
        string $alias,
        StorageInterface|callable $storage,
        bool $replace = false,
    ): StorageManager {
        return self::getManager()->addStorage($alias, $storage, $replace);
    }

    /**
     * Resolve a storage driver by alias.
     */
    public static function storage(?string $alias = null): StorageInterface
    {
        return self::getManager()->storage($alias);
    }

    /**
     * Write file contents into the selected storage.
     *
     * @param array{name?: string, mime_type?: string, metadata?: array<string, mixed>} $options
     */
    public static function put(
        string $location,
        string $contents,
        array $options = [],
        ?string $storage = null,
    ): StoredFile {
        return self::getManager()->put($location, $contents, $options, $storage);
    }

    /**
     * Return file metadata when the location exists.
     */
    public static function get(string $location, ?string $storage = null): ?StoredFile
    {
        return self::getManager()->get($location, $storage);
    }

    /**
     * Read raw file contents from storage.
     */
    public static function read(string $location, ?string $storage = null): string
    {
        return self::getManager()->read($location, $storage);
    }

    /**
     * Determine whether a file exists in storage.
     */
    public static function exists(string $location, ?string $storage = null): bool
    {
        return self::getManager()->exists($location, $storage);
    }

    /**
     * Delete a file from storage.
     */
    public static function delete(string $location, ?string $storage = null): bool
    {
        return self::getManager()->delete($location, $storage);
    }

    /**
     * Persist an uploaded file using the selected storage driver.
     *
     * @param array|UploadedFile $file
     * @param array{
     *     name?: string,
     *     mime_type?: string,
     *     metadata?: array<string, mixed>,
     *     allowed_extensions?: list<string>
     * } $options
     */
    public static function upload(
        array|UploadedFile $file,
        ?string $location = null,
        array $options = [],
        ?string $storage = null,
    ): StoredFile {
        return self::getManager()->upload($file, $location, $options, $storage);
    }

    /**
     * Forward unknown static calls to the storage manager.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if (!method_exists(self::getManager(), $name)) {
            throw new BadMethodCallException(sprintf('Storage facade method "%s" is not supported.', $name));
        }

        return self::getManager()->{$name}(...$arguments);
    }
}
