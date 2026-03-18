<?php

declare(strict_types=1);

namespace Myxa;

use InvalidArgumentException;
use Myxa\Container\Container;
use Myxa\Support\ServiceProvider;

/**
 * Core framework application container.
 *
 * In addition to dependency resolution, the application coordinates service
 * provider registration and one-time booting.
 */
class Application extends Container
{
    /** @var array<class-string<ServiceProvider>, ServiceProvider> */
    private array $providers = [];

    /** @var array<class-string<ServiceProvider>, true> */
    private array $bootedProviders = [];

    private bool $booted = false;

    public function __construct()
    {
        parent::__construct();
        $this->instance(self::class, $this);
    }

    /**
     * Register a service provider instance or provider class name.
     *
     * Providers are only registered once. If the application has already been
     * booted, the provider is booted immediately after registration.
     *
     * @param ServiceProvider|string $provider Provider instance or provider class name.
     *
     * @throws InvalidArgumentException When a class name does not extend ServiceProvider.
     */
    public function register(ServiceProvider|string $provider): ServiceProvider
    {
        if (is_string($provider)) {
            if (!is_subclass_of($provider, ServiceProvider::class)) {
                throw new InvalidArgumentException(sprintf('Provider [%s] must extend %s.', $provider, ServiceProvider::class));
            }

            $provider = new $provider();
        }

        $providerClass = $provider::class;
        if (isset($this->providers[$providerClass])) {
            return $this->providers[$providerClass];
        }

        $provider->setApplication($this);
        $provider->register();

        $this->providers[$providerClass] = $provider;

        if ($this->booted) {
            $provider->boot();
            $this->bootedProviders[$providerClass] = true;
        }

        return $provider;
    }

    /**
     * Boot all registered providers once.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $providerClass => $provider) {
            if (isset($this->bootedProviders[$providerClass])) {
                continue;
            }

            $provider->boot();
            $this->bootedProviders[$providerClass] = true;
        }

        $this->booted = true;
    }

    /**
     * Indicate whether provider booting has already completed.
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Return a previously-registered provider instance, if available.
     *
     * @param class-string<ServiceProvider> $providerClass
     *
     * @return ServiceProvider|null Registered provider instance when found.
     */
    public function getProvider(string $providerClass): ?ServiceProvider
    {
        return $this->providers[$providerClass] ?? null;
    }
}
