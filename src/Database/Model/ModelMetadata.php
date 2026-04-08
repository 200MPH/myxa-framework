<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use LogicException;
use Myxa\Database\Attributes\Cast;
use Myxa\Database\Attributes\Guarded;
use Myxa\Database\Attributes\Hidden;
use Myxa\Database\Attributes\Hook;
use Myxa\Database\Attributes\Internal;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;

final class ModelMetadata
{
    /** @var array<class-string, array<string, ReflectionProperty>> */
    private static array $declaredPropertyCache = [];

    /** @var array<class-string, array<string, true>> */
    private static array $guardedPropertyCache = [];

    /** @var array<class-string, array<string, true>> */
    private static array $hiddenPropertyCache = [];

    /** @var array<class-string, array<string, Cast>> */
    private static array $castPropertyCache = [];

    /** @var array<class-string, array<string, list<ReflectionMethod>>> */
    private static array $hookMethodCache = [];

    public function __construct(private readonly object $model)
    {
    }

    public function hasDeclaredProperty(string $name): bool
    {
        return array_key_exists($name, $this->declaredProperties());
    }

    /**
     * @return array<string, ReflectionProperty>
     */
    public function declaredProperties(): array
    {
        return self::$declaredPropertyCache[$this->model::class] ??= $this->buildDeclaredPropertyCache();
    }

    public function isGuardedProperty(string $name): bool
    {
        return isset($this->guardedProperties()[$name]);
    }

    /**
     * @return array<string, true>
     */
    public function hiddenProperties(): array
    {
        return self::$hiddenPropertyCache[$this->model::class] ??= $this->buildAttributedPropertyCache(Hidden::class);
    }

    public function castForProperty(string $name): ?Cast
    {
        return $this->castProperties()[$name] ?? null;
    }

    /**
     * @return list<ReflectionMethod>
     */
    public function hookMethodsFor(HookEvent $event): array
    {
        return $this->hookMethods()[$event->value] ?? [];
    }

    /**
     * @return array<string, true>
     */
    private function guardedProperties(): array
    {
        return self::$guardedPropertyCache[$this->model::class] ??= $this->buildAttributedPropertyCache(Guarded::class);
    }

    /**
     * @return array<string, Cast>
     */
    private function castProperties(): array
    {
        return self::$castPropertyCache[$this->model::class] ??= $this->buildCastPropertyCache();
    }

    /**
     * @return array<string, list<ReflectionMethod>>
     */
    private function hookMethods(): array
    {
        return self::$hookMethodCache[$this->model::class] ??= $this->buildHookMethodCache();
    }

    /**
     * @return array<string, ReflectionProperty>
     */
    private function buildDeclaredPropertyCache(): array
    {
        $properties = [];
        $seen = [];
        $reflection = new ReflectionObject($this->model);

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            if ($property->isStatic() || isset($seen[$name]) || $this->isInternalProperty($property)) {
                continue;
            }

            $seen[$name] = true;
            $properties[$name] = $property;
        }

        return $properties;
    }

    /**
     * @param class-string $attributeClass
     * @return array<string, true>
     */
    private function buildAttributedPropertyCache(string $attributeClass): array
    {
        $properties = [];

        foreach ($this->declaredProperties() as $name => $property) {
            if ($property->getAttributes($attributeClass) === []) {
                continue;
            }

            $properties[$name] = true;
        }

        return $properties;
    }

    /**
     * @return array<string, Cast>
     */
    private function buildCastPropertyCache(): array
    {
        $properties = [];

        foreach ($this->declaredProperties() as $name => $property) {
            $attributes = $property->getAttributes(Cast::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($attributes === []) {
                continue;
            }

            if (count($attributes) > 1) {
                throw new LogicException(sprintf(
                    'Property "%s" on model %s cannot declare more than one Cast attribute.',
                    $name,
                    $this->model::class,
                ));
            }

            $properties[$name] = $attributes[0]->newInstance();
        }

        return $properties;
    }

    /**
     * @return array<string, list<ReflectionMethod>>
     */
    private function buildHookMethodCache(): array
    {
        $methods = [];
        $reflection = new ReflectionObject($this->model);

        foreach ($reflection->getMethods() as $method) {
            if ($method->isStatic()) {
                continue;
            }

            $attributes = $method->getAttributes(Hook::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($attributes === []) {
                continue;
            }

            if ($method->getNumberOfRequiredParameters() > 0) {
                throw new LogicException(sprintf(
                    'Hook method "%s" on model %s cannot require parameters.',
                    $method->getName(),
                    $this->model::class,
                ));
            }

            $method->setAccessible(true);

            foreach ($attributes as $attribute) {
                $hook = $attribute->newInstance();
                $methods[$hook->event->value] ??= [];
                $methods[$hook->event->value][] = $method;
            }
        }

        return $methods;
    }

    private function isInternalProperty(ReflectionProperty $property): bool
    {
        if ($property->getAttributes(Internal::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
            return true;
        }

        return $this->inheritsInternalProperty($property->getName(), $property->getDeclaringClass()->getName());
    }

    private function inheritsInternalProperty(string $propertyName, string $declaringClass): bool
    {
        $parentClass = get_parent_class($declaringClass);

        while (is_string($parentClass)) {
            $reflection = new ReflectionClass($parentClass);
            if ($reflection->hasProperty($propertyName)) {
                $property = $reflection->getProperty($propertyName);

                return $property->getAttributes(Internal::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
            }

            $parentClass = get_parent_class($parentClass);
        }

        return false;
    }
}
