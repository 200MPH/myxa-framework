<?php

declare(strict_types=1);

namespace Myxa\Container;

use Closure;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Default container implementation with simple autowiring support.
 *
 * It provides the framework's richer registration API while also satisfying
 * PSR-11 for packages that only need `get()` and `has()`.
 */
class Container implements ContainerInterface
{
    /**
     * @var array<string, array{concrete: Closure|string, shared: bool}>
     */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var list<string> */
    private array $buildStack = [];

    public function __construct()
    {
        $this->instance(ContainerInterface::class, $this);
        $this->instance(PsrContainerInterface::class, $this);
        $this->instance(self::class, $this);
    }

    /**
     * Resolve an entry through the PSR-11 API.
     *
     * @param string $id Container key or class/interface name to resolve.
     *
     * @throws NotFoundException
     * @throws BindingResolutionException
     */
    public function get(string $id)
    {
        return $this->make($id);
    }

    /**
     * Register a transient binding that is rebuilt for each resolution.
     *
     * @param string $abstract Container key or abstract type being registered.
     * @param Closure|string|null $concrete Factory closure or concrete class name.
     */
    public function bind(string $abstract, Closure|string|null $concrete = null): static
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'shared' => false,
        ];

        unset($this->instances[$abstract]);

        return $this;
    }

    /**
     * Register a shared binding that is instantiated once and then cached.
     *
     * @param string $abstract Container key or abstract type being registered.
     * @param Closure|string|null $concrete Factory closure or concrete class name.
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): static
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'shared' => true,
        ];

        unset($this->instances[$abstract]);

        return $this;
    }

    /**
     * Store a fully-built object or value under a container key.
     *
     * @param string $abstract Container key or abstract type being registered.
     * @param mixed $instance Pre-built value to store.
     *
     * @return mixed The same instance that was registered.
     */
    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    /**
     * Resolve an entry from bindings, stored instances, or autowirable classes.
     *
     * @param string $abstract Container key or class/interface name to resolve.
     * @param array<string, mixed> $parameters Named parameter overrides used during resolution.
     *
     * @throws NotFoundException
     * @throws BindingResolutionException
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        if (!$this->hasResolvableEntry($abstract)) {
            throw new NotFoundException(sprintf('Container entry [%s] was not found.', $abstract));
        }

        $binding = $this->bindings[$abstract] ?? null;
        $concrete = $binding['concrete'] ?? $abstract;
        $resolved = $this->build($concrete, $parameters);

        if (($binding['shared'] ?? false) === true) {
            $this->instances[$abstract] = $resolved;
        }

        return $resolved;
    }

    /**
     * Determine whether an entry can be resolved by the container.
     *
     * @param string $abstract Container key or class/interface name to check.
     */
    public function has(string $abstract): bool
    {
        return $this->hasResolvableEntry($abstract);
    }

    /**
     * Invoke a callable and auto-resolve any missing class-typed arguments.
     *
     * @param mixed $callable Callable target to invoke through the container.
     * @param array<string, mixed> $parameters Named parameter overrides used during invocation.
     *
     * @throws BindingResolutionException
     */
    public function call(mixed $callable, array $parameters = []): mixed
    {
        [$reflection, $target] = $this->reflectCallable($callable);
        $arguments = $this->resolveParameters($reflection, $parameters);

        if ($reflection instanceof ReflectionMethod) {
            if ($target === null && !$reflection->isStatic()) {
                $target = $this->make($reflection->getDeclaringClass()->getName());
            }

            return $reflection->invokeArgs($target, $arguments);
        }

        return $reflection->invokeArgs($arguments);
    }

    /**
     * Build a binding target from a closure or class name.
     *
     * @param Closure|string $concrete Factory closure or concrete class name.
     * @param array<string, mixed> $parameters Named parameter overrides used during resolution.
     *
     * @throws BindingResolutionException
     */
    protected function build(Closure|string $concrete, array $parameters = []): mixed
    {
        if ($concrete instanceof Closure) {
            return $this->call($concrete, $parameters);
        }

        if (!class_exists($concrete)) {
            throw new BindingResolutionException(sprintf('Target [%s] is not instantiable.', $concrete));
        }

        return $this->buildClass($concrete, $parameters);
    }

    /**
     * Instantiate a class by reflecting its constructor dependencies.
     *
     * @param string $className Concrete class name to instantiate.
     * @param array<string, mixed> $parameters Named parameter overrides used during resolution.
     *
     * @throws BindingResolutionException
     */
    protected function buildClass(string $className, array $parameters = []): object
    {
        if (in_array($className, $this->buildStack, true)) {
            throw new BindingResolutionException(sprintf('Circular dependency detected while resolving [%s].', $className));
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $exception) {
            throw new BindingResolutionException(
                sprintf('Unable to reflect class [%s].', $className),
                previous: $exception,
            );
        }

        if (!$reflection->isInstantiable()) {
            throw new BindingResolutionException(sprintf('Target [%s] is not instantiable.', $className));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $className();
        }

        $this->buildStack[] = $className;

        try {
            $dependencies = $this->resolveParameters($constructor, $parameters);

            /** @var object $instance */
            $instance = $reflection->newInstanceArgs($dependencies);

            return $instance;
        } finally {
            array_pop($this->buildStack);
        }
    }

    /**
     * Convert a callable into a reflection object plus optional target object.
     *
     * @param mixed $callable Callable target to inspect.
     *
     * @return array{0: ReflectionMethod|ReflectionFunction, 1: object|null}
     *
     * @throws BindingResolutionException
     */
    private function reflectCallable(mixed $callable): array
    {
        if (is_array($callable)) {
            if (
                count($callable) !== 2
                || !array_key_exists(0, $callable)
                || !array_key_exists(1, $callable)
                || (!is_object($callable[0]) && !is_string($callable[0]))
                || !is_string($callable[1])
            ) {
                throw new BindingResolutionException(sprintf(
                    'Target [%s] is not a valid callable.',
                    $this->describeCallable($callable),
                ));
            }

            return [new ReflectionMethod($callable[0], $callable[1]), is_object($callable[0]) ? $callable[0] : null];
        }

        if (is_string($callable) && str_contains($callable, '::')) {
            [$class, $method] = explode('::', $callable, 2);

            return [new ReflectionMethod($class, $method), null];
        }

        if ($callable instanceof Closure || is_callable($callable)) {
            return [new ReflectionFunction(Closure::fromCallable($callable)), null];
        }

        throw new BindingResolutionException(sprintf(
            'Target [%s] is not a valid callable.',
            $this->describeCallable($callable),
        ));
    }

    /**
     * Create a readable description for invalid callable targets.
     */
    private function describeCallable(mixed $callable): string
    {
        if (is_object($callable)) {
            return $callable::class;
        }

        if (is_array($callable)) {
            return 'array';
        }

        return gettype($callable);
    }

    /**
     * Resolve all parameters required by a reflected callable.
     *
     * @param ReflectionMethod|ReflectionFunction $reflection Reflected callable metadata.
     * @param array<string, mixed> $parameters
     *
     * @return list<mixed>
     */
    private function resolveParameters(ReflectionMethod|ReflectionFunction $reflection, array $parameters): array
    {
        $resolved = [];

        foreach ($reflection->getParameters() as $parameter) {
            $resolved[] = $this->resolveParameter($parameter, $parameters);
        }

        return $resolved;
    }

    /**
     * Resolve a single reflected parameter from overrides, type hints, or defaults.
     *
     * @param ReflectionParameter $parameter Reflected parameter metadata.
     * @param array<string, mixed> $parameters
     *
     * @throws BindingResolutionException
     */
    private function resolveParameter(ReflectionParameter $parameter, array $parameters): mixed
    {
        if (array_key_exists($parameter->getName(), $parameters)) {
            return $parameters[$parameter->getName()];
        }

        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            if (array_key_exists($typeName, $parameters)) {
                return $parameters[$typeName];
            }

            if ($typeName === ContainerInterface::class || $typeName === self::class || is_a($this, $typeName)) {
                return $this;
            }

            return $this->make($typeName);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $context = $parameter->getDeclaringClass()?->getName() ?? $parameter->getDeclaringFunction()->getName();

        throw new BindingResolutionException(sprintf(
            'Unable to resolve dependency [$%s] in [%s].',
            $parameter->getName(),
            $context,
        ));
    }

    /**
     * Internal helper used by `has()` and `make()` to determine whether the
     * container knows about a binding, stored instance, or autowirable class.
     *
     * @param string $abstract Container key or class/interface name to check.
     */
    private function hasResolvableEntry(string $abstract): bool
    {
        return array_key_exists($abstract, $this->instances)
            || array_key_exists($abstract, $this->bindings)
            || class_exists($abstract);
    }
}
