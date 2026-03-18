<?php

declare(strict_types=1);

namespace Myxa\Container;

use Closure;
use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Rich application container contract used by the framework.
 *
 * Extends PSR-11 with binding, singleton, and callable-invocation helpers
 * that are convenient when bootstrapping a framework or application.
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Register a transient binding.
     *
     * The resolved value is rebuilt every time it is requested unless it is
     * later replaced by an explicit instance or singleton binding.
     *
     * @param string $abstract Container key or abstract type being registered.
     * @param Closure|string|null $concrete Factory closure or concrete class name.
     */
    public function bind(string $abstract, Closure|string|null $concrete = null): static;

    /**
     * Register a shared binding.
     *
     * The first resolved value is cached and returned for all future lookups.
     *
     * @param string $abstract Container key or abstract type being registered.
     * @param Closure|string|null $concrete Factory closure or concrete class name.
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): static;

    /**
     * Store an already-built instance in the container.
     *
     * @param string $abstract Container key or abstract type being registered.
     * @param mixed $instance Pre-built value to store.
     *
     * @return mixed The same instance for fluent inline registration patterns.
     */
    public function instance(string $abstract, mixed $instance): mixed;

    /**
     * Resolve an entry from the container.
     *
     * @param string $abstract Container key or class/interface name to resolve.
     * @param array<string, mixed> $parameters Named parameter overrides used during resolution.
     *
     * @throws NotFoundException When the entry is unknown to the container.
     * @throws BindingResolutionException When the entry exists but cannot be built.
     */
    public function make(string $abstract, array $parameters = []): mixed;

    /**
     * Determine whether the container knows how to resolve the given entry.
     *
     * @param string $abstract Container key or class/interface name to check.
     */
    public function has(string $abstract): bool;

    /**
     * Invoke a callable while auto-resolving missing class-typed arguments.
     *
     * @param callable $callable Callable to invoke through the container.
     * @param array<string, mixed> $parameters Named parameter overrides used during invocation.
     *
     * @throws BindingResolutionException When an argument cannot be resolved.
     */
    public function call(callable $callable, array $parameters = []): mixed;
}
