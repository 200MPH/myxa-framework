<?php

declare(strict_types=1);

namespace Myxa\Validation;

use Myxa\Application;
use Myxa\Support\Facades\Validator as ValidatorFacade;
use Myxa\Support\ServiceProvider;

final class ValidationServiceProvider extends ServiceProvider
{
    /**
     * Register the shared validation manager.
     */
    public function register(): void
    {
        $this->app()->singleton(ValidationManager::class);
        $this->app()->singleton(
            'validator',
            static fn (Application $app): ValidationManager => $app->make(ValidationManager::class),
        );
    }

    /**
     * Initialize the validator facade with the shared manager.
     */
    public function boot(): void
    {
        ValidatorFacade::setManager($this->app()->make(ValidationManager::class));
    }
}
