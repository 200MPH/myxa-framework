<?php

declare(strict_types=1);

namespace Myxa\Database;

use Myxa\Application;
use Myxa\Support\Facades\DB;
use Myxa\Support\ServiceProvider;

/**
 * Registers the framework's database manager and database facade bindings.
 */
final class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * @param array<string, PdoConnection|PdoConnectionConfig|callable> $connections
     * @param string $defaultConnection Default connection alias for the manager.
     */
    public function __construct(
        private readonly array $connections = [],
        private readonly string $defaultConnection = 'main',
    ) {
    }

    /**
     * Register the shared database manager and its container alias.
     */
    public function register(): void
    {
        $connections = $this->connections;
        $defaultConnection = $this->defaultConnection;

        $this->app()->singleton(DatabaseManager::class, static function () use (
            $connections,
            $defaultConnection,
        ): DatabaseManager {
            $manager = new DatabaseManager($defaultConnection);

            foreach ($connections as $alias => $connection) {
                $manager->addConnection($alias, $connection);
            }

            return $manager;
        });

        $this->app()->singleton('db', static fn (Application $app): DatabaseManager => $app->make(DatabaseManager::class));
    }

    /**
     * Point the static DB facade at the application's managed database service.
     */
    public function boot(): void
    {
        DB::setManager($this->app()->make(DatabaseManager::class));
    }
}
