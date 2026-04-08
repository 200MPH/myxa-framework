<?php

declare(strict_types=1);

namespace Myxa\Support;

use LogicException;
use Myxa\Application;

/**
 * Base class for framework service providers.
 *
 * Providers register bindings during `register()` and may perform runtime
 * bootstrapping during `boot()` once the application is ready.
 */
abstract class ServiceProvider
{
    private ?Application $app = null;

    /**
     * Attach the provider to the owning application instance.
     *
     * @param Application $app The application that owns this provider.
     */
    final public function setApplication(Application $app): void
    {
        $this->app = $app;
    }

    /**
     * Register bindings, singletons, and other container entries.
     */
    abstract public function register(): void;

    /**
     * Perform any post-registration boot logic.
     */
    public function boot(): void
    {
    }

    /**
     * Return the attached application instance.
     *
     * @throws LogicException When the provider has not been registered yet.
     */
    final protected function app(): Application
    {
        if (!$this->app instanceof Application) {
            throw new LogicException(sprintf(
                'Service provider [%s] is not attached to an application.',
                static::class,
            ));
        }

        return $this->app;
    }
}
