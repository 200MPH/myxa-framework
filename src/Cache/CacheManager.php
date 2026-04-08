<?php

declare(strict_types=1);

namespace Myxa\Cache;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use RuntimeException;

final class CacheManager
{
    private const string DEFAULT_STORE = 'local';

    /** @var array<string, CacheStoreInterface> */
    private array $stores = [];

    /** @var array<string, Closure(self): CacheStoreInterface> */
    private array $storeFactories = [];

    /**
     * @param CacheStoreInterface|(callable(self): CacheStoreInterface)|(callable(): CacheStoreInterface)|null $store
     */
    public function __construct(
        private string $defaultStore = self::DEFAULT_STORE,
        CacheStoreInterface|callable|null $store = null,
    ) {
        $this->defaultStore = $this->normalizeStoreName($this->defaultStore);

        if ($store !== null) {
            $this->addStore($this->defaultStore, $store);
        }
    }

    /**
     * @param CacheStoreInterface|(callable(self): CacheStoreInterface)|(callable(): CacheStoreInterface) $store
     */
    public function addStore(string $alias, CacheStoreInterface|callable $store, bool $replace = false): self
    {
        $alias = $this->normalizeStoreName($alias);

        if (!$replace && $this->hasManagedStore($alias)) {
            throw new RuntimeException(sprintf('Cache store alias "%s" is already registered.', $alias));
        }

        unset($this->stores[$alias], $this->storeFactories[$alias]);

        if ($store instanceof CacheStoreInterface) {
            $this->stores[$alias] = $store;

            return $this;
        }

        $this->storeFactories[$alias] = $this->normalizeStoreFactory($alias, $store);

        return $this;
    }

    public function hasStore(string $alias): bool
    {
        $alias = $this->normalizeStoreName($alias);

        return $this->hasManagedStore($alias);
    }

    public function store(?string $alias = null): CacheStoreInterface
    {
        $resolvedAlias = $this->resolveStoreName($alias);

        if (isset($this->stores[$resolvedAlias])) {
            return $this->stores[$resolvedAlias];
        }

        if (isset($this->storeFactories[$resolvedAlias])) {
            $this->stores[$resolvedAlias] = $this->resolveStoreFactory(
                $resolvedAlias,
                $this->storeFactories[$resolvedAlias],
            );

            return $this->stores[$resolvedAlias];
        }

        throw new RuntimeException(sprintf('Cache store alias "%s" is not registered.', $resolvedAlias));
    }

    public function getDefaultStore(): string
    {
        return $this->defaultStore;
    }

    public function setDefaultStore(string $alias): self
    {
        $this->defaultStore = $this->normalizeStoreName($alias);

        return $this;
    }

    public function get(string $key, mixed $default = null, ?string $store = null): mixed
    {
        return $this->store($store)->get($key, $default);
    }

    /**
     * Store a value in the cache.
     *
     * @param int|null $ttl Time to live in seconds. `null` stores the value until it is removed manually.
     */
    public function put(string $key, mixed $value, ?int $ttl = null, ?string $store = null): bool
    {
        return $this->store($store)->put($key, $value, $ttl);
    }

    /**
     * Store a value in the cache without an expiration time.
     */
    public function forever(string $key, mixed $value, ?string $store = null): bool
    {
        return $this->put($key, $value, null, $store);
    }

    public function forget(string $key, ?string $store = null): bool
    {
        return $this->store($store)->forget($key);
    }

    public function has(string $key, ?string $store = null): bool
    {
        return $this->store($store)->has($key);
    }

    public function clear(?string $store = null): bool
    {
        return $this->store($store)->clear();
    }

    /**
     * Get a cached value or resolve and store it when missing.
     *
     * @param int|null $ttl Time to live in seconds. `null` stores the value until it is removed manually.
     */
    public function remember(string $key, callable $resolver, ?int $ttl = null, ?string $store = null): mixed
    {
        $cacheStore = $this->store($store);

        if ($cacheStore->has($key)) {
            return $cacheStore->get($key);
        }

        $value = $resolver();
        $cacheStore->put($key, $value, $ttl);

        return $value;
    }

    private function hasManagedStore(string $alias): bool
    {
        return isset($this->stores[$alias]) || isset($this->storeFactories[$alias]);
    }

    private function resolveStoreName(?string $alias): string
    {
        return $alias === null ? $this->defaultStore : $this->normalizeStoreName($alias);
    }

    private function normalizeStoreName(string $alias): string
    {
        $alias = trim($alias);

        if ($alias === '') {
            throw new InvalidArgumentException('Cache store alias cannot be empty.');
        }

        return $alias;
    }

    /**
     * @param Closure(self): CacheStoreInterface $factory
     */
    private function resolveStoreFactory(string $alias, Closure $factory): CacheStoreInterface
    {
        $store = $factory($this);
        if (!$store instanceof CacheStoreInterface) {
            throw new RuntimeException(sprintf(
                'Cache store factory for alias "%s" must return %s.',
                $alias,
                CacheStoreInterface::class,
            ));
        }

        return $store;
    }

    /**
     * @param (callable(self): CacheStoreInterface)|(callable(): CacheStoreInterface) $factory
     * @return Closure(self): CacheStoreInterface
     */
    private function normalizeStoreFactory(string $alias, callable $factory): Closure
    {
        $reflection = new ReflectionFunction(Closure::fromCallable($factory));
        $parameterCount = $reflection->getNumberOfParameters();

        if ($parameterCount > 1) {
            throw new InvalidArgumentException(sprintf(
                'Cache store factory for alias "%s" must accept zero or one parameter.',
                $alias,
            ));
        }

        if ($parameterCount === 0) {
            return static fn (self $manager): CacheStoreInterface => $factory();
        }

        return static fn (self $manager): CacheStoreInterface => $factory($manager);
    }
}
