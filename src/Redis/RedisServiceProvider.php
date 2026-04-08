<?php

declare(strict_types=1);

namespace Myxa\Redis;

use Myxa\Application;
use Myxa\Redis\Connection\RedisConnection;
use Myxa\Support\Facades\Redis;
use Myxa\Support\ServiceProvider;

final class RedisServiceProvider extends ServiceProvider
{
    /**
     * @param array<string, RedisConnection|callable> $connections
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

        $this->app()->singleton(RedisManager::class, static function () use (
            $connections,
            $defaultConnection,
        ): RedisManager {
            $defaultManagedConnection = $connections[$defaultConnection] ?? null;
            $manager = new RedisManager($defaultConnection, $defaultManagedConnection);

            foreach ($connections as $alias => $connection) {
                if ($alias === $defaultConnection && $defaultManagedConnection !== null) {
                    continue;
                }

                $manager->addConnection($alias, $connection);
            }

            return $manager;
        });

        $this->app()->singleton(
            'redis',
            static fn (Application $app): RedisManager => $app->make(RedisManager::class),
        );
    }

    public function boot(): void
    {
        Redis::setManager($this->app()->make(RedisManager::class));
    }
}
