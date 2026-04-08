<?php

declare(strict_types=1);

namespace Myxa\Mongo;

use Myxa\Application;
use Myxa\Mongo\Connection\MongoConnection;
use Myxa\Support\Facades\Mongo;
use Myxa\Support\ServiceProvider;

final class MongoServiceProvider extends ServiceProvider
{
    /**
     * @param array<string, MongoConnection|callable> $connections
     */
    public function __construct(
        private readonly array $connections = [],
        private readonly string $defaultConnection = 'main',
    ) {
    }

    public function register(): void
    {
        $connections = $this->connections;
        $defaultConnection = $this->defaultConnection;

        $this->app()->singleton(MongoManager::class, static function () use (
            $connections,
            $defaultConnection,
        ): MongoManager {
            $manager = new MongoManager($defaultConnection);

            foreach ($connections as $alias => $connection) {
                $manager->addConnection($alias, $connection);
            }

            return $manager;
        });

        $this->app()->singleton(
            'mongo',
            static fn (Application $app): MongoManager => $app->make(MongoManager::class),
        );
    }

    public function boot(): void
    {
        $manager = $this->app()->make(MongoManager::class);

        Mongo::setManager($manager);
        MongoModel::setManager($manager);
    }
}
