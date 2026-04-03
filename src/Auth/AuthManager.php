<?php

declare(strict_types=1);

namespace Myxa\Auth;

use InvalidArgumentException;
use Myxa\Application;
use Myxa\Http\Request;

/**
 * Resolve and cache configured authentication guards.
 */
final class AuthManager
{
    /**
     * @var array<string, AuthGuardInterface|string>
     */
    private array $guards = [];

    /**
     * @var array<string, mixed>
     */
    private array $userCache = [];

    private string $defaultGuard = 'web';

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * Register or replace a named guard.
     *
     * @param AuthGuardInterface|class-string<AuthGuardInterface> $guard
     */
    public function extend(string $name, AuthGuardInterface|string $guard): self
    {
        $this->guards[$name] = $guard;
        $this->clearCacheFor($name);

        return $this;
    }

    /**
     * Return the resolved guard for the provided name.
     */
    public function guard(?string $name = null): AuthGuardInterface
    {
        $name ??= $this->defaultGuard;
        $guard = $this->guards[$name] ?? null;

        if ($guard === null) {
            throw new InvalidArgumentException(sprintf('Authentication guard [%s] is not registered.', $name));
        }

        if ($guard instanceof AuthGuardInterface) {
            return $guard;
        }

        $resolved = $this->app->make($guard);
        if (!$resolved instanceof AuthGuardInterface) {
            throw new InvalidArgumentException(sprintf(
                'Authentication guard [%s] must implement %s.',
                $guard,
                AuthGuardInterface::class,
            ));
        }

        $this->guards[$name] = $resolved;

        return $resolved;
    }

    /**
     * Return the authenticated user for the request and guard, if any.
     */
    public function user(Request $request, ?string $guard = null): mixed
    {
        $guardName = $guard ?? $this->defaultGuard;
        $cacheKey = $this->cacheKey($guardName, $request);

        if (!array_key_exists($cacheKey, $this->userCache)) {
            $this->userCache[$cacheKey] = $this->guard($guardName)->user($request);
        }

        return $this->userCache[$cacheKey];
    }

    /**
     * Determine whether the request is authenticated for the named guard.
     */
    public function check(Request $request, ?string $guard = null): bool
    {
        return $this->user($request, $guard) !== null;
    }

    /**
     * Set the default guard name used when one is not provided explicitly.
     */
    public function shouldUse(string $guard): void
    {
        $this->defaultGuard = $guard;
    }

    /**
     * Return the current default guard name.
     */
    public function defaultGuard(): string
    {
        return $this->defaultGuard;
    }

    private function clearCacheFor(string $guard): void
    {
        foreach (array_keys($this->userCache) as $cacheKey) {
            if (str_starts_with($cacheKey, $guard . ':')) {
                unset($this->userCache[$cacheKey]);
            }
        }
    }

    private function cacheKey(string $guard, Request $request): string
    {
        return sprintf('%s:%d', $guard, spl_object_id($request));
    }
}
