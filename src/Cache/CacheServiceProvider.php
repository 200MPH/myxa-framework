<?php

declare(strict_types=1);

namespace Myxa\Cache;

use Myxa\Application;
use Myxa\Cache\Store\FileCacheStore;
use Myxa\Support\Facades\Cache;
use Myxa\Support\ServiceProvider;

final class CacheServiceProvider extends ServiceProvider
{
    /**
     * @param array<string, CacheStoreInterface|callable> $stores
     */
    public function __construct(
        private readonly array $stores = [],
        private readonly string $defaultStore = 'local',
        private readonly string $defaultPath = 'data/cache',
    ) {
    }

    public function register(): void
    {
        $stores = $this->stores;
        $defaultStore = $this->defaultStore;
        $defaultPath = $this->defaultPath;

        $this->app()->singleton(CacheManager::class, static function () use (
            $stores,
            $defaultStore,
            $defaultPath,
        ): CacheManager {
            $defaultManagedStore = $stores[$defaultStore] ?? new FileCacheStore($defaultPath);
            $manager = new CacheManager($defaultStore, $defaultManagedStore);

            foreach ($stores as $alias => $store) {
                if ($alias === $defaultStore) {
                    continue;
                }

                $manager->addStore($alias, $store);
            }

            return $manager;
        });

        $this->app()->singleton(
            'cache',
            static fn (Application $app): CacheManager => $app->make(CacheManager::class),
        );
    }

    public function boot(): void
    {
        Cache::setManager($this->app()->make(CacheManager::class));
    }
}
