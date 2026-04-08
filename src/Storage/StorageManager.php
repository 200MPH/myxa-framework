<?php

declare(strict_types=1);

namespace Myxa\Storage;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use RuntimeException;

final class StorageManager
{
    /** @var array<string, StorageInterface> */
    private array $storages = [];

    /** @var array<string, Closure(self): StorageInterface> */
    private array $storageFactories = [];

    public function __construct(private string $defaultStorage = 'local')
    {
        $this->defaultStorage = $this->normalizeStorageName($this->defaultStorage);
    }

    /**
     * @param StorageInterface|callable(self): StorageInterface|callable(): StorageInterface $storage
     */
    public function addStorage(string $alias, StorageInterface|callable $storage, bool $replace = false): self
    {
        $alias = $this->normalizeStorageName($alias);

        if (!$replace && $this->hasStorage($alias)) {
            throw new RuntimeException(sprintf('Storage alias "%s" is already registered.', $alias));
        }

        unset($this->storages[$alias], $this->storageFactories[$alias]);

        if ($storage instanceof StorageInterface) {
            $this->storages[$alias] = $storage;

            return $this;
        }

        $this->storageFactories[$alias] = $this->normalizeStorageFactory($alias, $storage);

        return $this;
    }

    public function hasStorage(string $alias): bool
    {
        $alias = $this->normalizeStorageName($alias);

        return isset($this->storages[$alias]) || isset($this->storageFactories[$alias]);
    }

    public function storage(?string $alias = null): StorageInterface
    {
        $resolvedAlias = $this->resolveStorageName($alias);

        if (isset($this->storages[$resolvedAlias])) {
            return $this->storages[$resolvedAlias];
        }

        if (isset($this->storageFactories[$resolvedAlias])) {
            $storage = ($this->storageFactories[$resolvedAlias])($this);

            if (!$storage instanceof StorageInterface) {
                throw new RuntimeException(sprintf(
                    'Storage factory for alias "%s" must return %s.',
                    $resolvedAlias,
                    StorageInterface::class,
                ));
            }

            $this->storages[$resolvedAlias] = $storage;

            return $storage;
        }

        throw new RuntimeException(sprintf('Storage alias "%s" is not registered.', $resolvedAlias));
    }

    public function getDefaultStorage(): string
    {
        return $this->defaultStorage;
    }

    public function setDefaultStorage(string $alias): self
    {
        $this->defaultStorage = $this->normalizeStorageName($alias);

        return $this;
    }

    /**
     * @param array{name?: string, mime_type?: string, metadata?: array<string, mixed>} $options
     */
    public function put(
        string $location,
        string $contents,
        array $options = [],
        ?string $storage = null,
    ): StoredFile {
        return $this->storage($storage)->put($location, $contents, $options);
    }

    public function get(string $location, ?string $storage = null): ?StoredFile
    {
        return $this->storage($storage)->get($location);
    }

    public function read(string $location, ?string $storage = null): string
    {
        return $this->storage($storage)->read($location);
    }

    public function delete(string $location, ?string $storage = null): bool
    {
        return $this->storage($storage)->delete($location);
    }

    public function exists(string $location, ?string $storage = null): bool
    {
        return $this->storage($storage)->exists($location);
    }

    /**
     * @param array|UploadedFile $file
     * @param array{
     *     name?: string,
     *     mime_type?: string,
     *     metadata?: array<string, mixed>,
     *     allowed_extensions?: list<string>
     * } $options
     */
    public function upload(
        array|UploadedFile $file,
        ?string $location = null,
        array $options = [],
        ?string $storage = null,
    ): StoredFile {
        $allowedExtensions = $options['allowed_extensions'] ?? [];
        unset($options['allowed_extensions']);

        $upload = $file instanceof UploadedFile
            ? $file
            : UploadedFile::fromArray($file, is_array($allowedExtensions) ? $allowedExtensions : []);

        return $upload->store($this->storage($storage), $location, $options);
    }

    private function resolveStorageName(?string $alias): string
    {
        if ($alias === null) {
            return $this->defaultStorage;
        }

        return $this->normalizeStorageName($alias);
    }

    private function normalizeStorageName(string $alias): string
    {
        $alias = trim($alias);

        if ($alias === '') {
            throw new InvalidArgumentException('Storage alias cannot be empty.');
        }

        return $alias;
    }

    /**
     * @param callable(self): StorageInterface|callable(): StorageInterface $factory
     * @return Closure(self): StorageInterface
     */
    private function normalizeStorageFactory(string $alias, callable $factory): Closure
    {
        $factory = Closure::fromCallable($factory);
        $reflection = new ReflectionFunction($factory);
        $parameterCount = $reflection->getNumberOfParameters();

        if ($parameterCount > 1) {
            throw new InvalidArgumentException(sprintf(
                'Storage factory for alias "%s" must accept zero or one parameter.',
                $alias,
            ));
        }

        if ($parameterCount === 0) {
            return static fn (self $manager): StorageInterface => $factory();
        }

        return static fn (self $manager): StorageInterface => $factory($manager);
    }
}
