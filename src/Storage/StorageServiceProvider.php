<?php

declare(strict_types=1);

namespace Myxa\Storage;

use Myxa\Application;
use Myxa\Support\Facades\Storage as StorageFacade;
use Myxa\Support\ServiceProvider;

final class StorageServiceProvider extends ServiceProvider
{
    /**
     * @param array<string, StorageInterface|callable> $storages
     */
    public function __construct(
        private readonly array $storages = [],
        private readonly string $defaultStorage = 'local',
    ) {
    }

    public function register(): void
    {
        $storages = $this->storages;
        $defaultStorage = $this->defaultStorage;

        $this->app()->singleton(
            StorageManager::class,
            static function () use ($storages, $defaultStorage): StorageManager {
                $manager = new StorageManager($defaultStorage);

                foreach ($storages as $alias => $storage) {
                    $manager->addStorage($alias, $storage);
                }

                return $manager;
            },
        );

        $this->app()->singleton(
            'storage',
            static fn (Application $app): StorageManager => $app->make(StorageManager::class),
        );
        $this->app()->singleton(
            'file',
            static fn (Application $app): StorageManager => $app->make(StorageManager::class),
        );
    }

    public function boot(): void
    {
        StorageFacade::setManager($this->app()->make(StorageManager::class));
    }
}
