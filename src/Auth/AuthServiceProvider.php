<?php

declare(strict_types=1);

namespace Myxa\Auth;

use Myxa\Application;
use Myxa\Support\ServiceProvider;

/**
 * Register the default authentication manager and built-in guards.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->app()->has(BearerTokenResolverInterface::class)) {
            $this->app()->singleton(BearerTokenResolverInterface::class, NullBearerTokenResolver::class);
        }

        if (!$this->app()->has(SessionUserResolverInterface::class)) {
            $this->app()->singleton(SessionUserResolverInterface::class, NullSessionUserResolver::class);
        }

        $this->app()->singleton(BearerTokenGuard::class);
        $this->app()->singleton(SessionGuard::class);
        $this->app()->singleton(AuthManager::class, static function (Application $app): AuthManager {
            $manager = new AuthManager($app);
            $manager->extend('web', SessionGuard::class);
            $manager->extend('api', BearerTokenGuard::class);

            return $manager;
        });
        $this->app()->singleton('auth', static fn (Application $app): AuthManager => $app->make(AuthManager::class));
    }
}
