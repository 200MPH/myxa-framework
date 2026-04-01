<?php

declare(strict_types=1);

namespace Myxa\Database;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use ReflectionObject;
use ReflectionProperty;

/**
 * Minimal active-record style model built on top of DatabaseManager.
 */
abstract class Model implements JsonSerializable
{
    private const array INTERNAL_PROPERTIES = [
        'attributes',
        'resolvedManager',
        'exists',
        'readOnly',
        'table',
        'primaryKey',
        'connection',
    ];

    /** @var array<class-string, array<string, ReflectionProperty>> */
    private static array $declaredPropertyCache = [];

    private static ?DatabaseManager $sharedManager = null;

    protected string $table = '';

    protected string $primaryKey = 'id';

    protected ?string $connection = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    private ?DatabaseManager $resolvedManager = null;

    private bool $exists = false;

    private bool $readOnly = false;

    /**
     * @param array<string, mixed> $attributes
     */
    final public function __construct(array $attributes = [], ?DatabaseManager $manager = null)
    {
        $this->resolvedManager = $manager;
        $this->fill($attributes);
    }

    public function __clone()
    {
        $this->exists = false;
        $this->setAttribute(static::primaryKey(), null);
    }

    public static function setManager(DatabaseManager $manager): void
    {
        self::$sharedManager = $manager;
    }

    public static function clearManager(): void
    {
        self::$sharedManager = null;
    }

    public static function table(): string
    {
        $table = trim(static::metadata()->table);
        if ($table === '') {
            throw new LogicException(sprintf('Model %s must define a non-empty $table property.', static::class));
        }

        return $table;
    }

    public static function primaryKey(): string
    {
        $primaryKey = trim(static::metadata()->primaryKey);
        if ($primaryKey === '') {
            throw new LogicException(sprintf('Model %s must define a non-empty $primaryKey property.', static::class));
        }

        return $primaryKey;
    }

    public static function connectionName(): ?string
    {
        return static::normalizeOptionalMetadata(static::metadata()->connection);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function make(array $attributes = []): static
    {
        return new static($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    public static function query(): ModelQuery
    {
        return static::newQuery();
    }

    /**
     * @return list<static>
     */
    public static function all(?int $limit = null, int $offset = 0): array
    {
        return static::newQuery()
            ->limit($limit, $offset)
            ->get();
    }

    public static function find(int|string $id): ?static
    {
        return static::newQuery()->find($id);
    }

    public static function findOrFail(int|string $id): static
    {
        return static::newQuery()->findOrFail($id);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function hydrate(array $attributes, ?DatabaseManager $manager = null): static
    {
        $model = new static([], $manager ?? static::sharedManager());
        $model->fill($attributes);
        $model->exists = true;

        return $model;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function setReadOnly(bool $readOnly = true): static
    {
        $this->readOnly = $readOnly;

        return $this;
    }

    public function getKey(): int|string|null
    {
        return $this->getAttribute(static::primaryKey());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Model attribute keys must be non-empty strings.');
            }

            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function getAttribute(string $key): mixed
    {
        if ($this->hasDeclaredProperty($key)) {
            return $this->readDeclaredProperty($key);
        }

        return $this->attributes[$key] ?? null;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        if ($this->hasDeclaredProperty($key)) {
            $this->writeDeclaredProperty($key, $value);

            return $this;
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->getAttribute($name) !== null;
    }

    public function __unset(string $name): void
    {
        if ($this->hasDeclaredProperty($name)) {
            $this->writeDeclaredProperty($name, null);

            return;
        }

        unset($this->attributes[$name]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->declaredAttributes(), $this->attributes);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function refresh(): static
    {
        $key = $this->getKey();
        if ($key === null || !$this->exists) {
            throw new LogicException('Cannot refresh a model that has not been persisted.');
        }

        $fresh = static::newQuery($this->manager())->find($key);
        if ($fresh === null) {
            throw new LogicException('Cannot refresh a model that no longer exists in storage.');
        }

        $this->copyStateFrom($fresh);

        return $this;
    }

    public function save(): bool
    {
        if ($this->readOnly) {
            return false;
        }

        $this->applyPersistenceHooks();

        if ($this->exists) {
            $key = $this->getKey();
            if ($key === null) {
                throw new LogicException('Cannot update a persisted model without a primary key.');
            }

            $attributes = $this->attributesForUpdate();
            if ($attributes === []) {
                throw new LogicException('Cannot update a model without any persisted attributes.');
            }

            $query = $this->manager()
                ->query()
                ->update(static::table())
                ->setMany($attributes)
                ->where(static::primaryKey(), '=', $key);

            $this->manager()->update($query->toSql(), $query->getBindings(), static::connectionName());

            return true;
        }

        $attributes = $this->attributesForInsert();
        if ($attributes === []) {
            throw new LogicException('Cannot insert a model without any persisted attributes.');
        }

        $query = $this->manager()
            ->query()
            ->insertInto(static::table())
            ->values($attributes);

        $insertedId = $this->manager()->insert($query->toSql(), $query->getBindings(), static::connectionName());
        if ($this->getKey() === null) {
            $this->setAttribute(static::primaryKey(), $insertedId);
        }

        $this->exists = true;

        return true;
    }

    public function delete(): bool
    {
        if ($this->readOnly || !$this->exists) {
            return false;
        }

        $key = $this->getKey();
        if ($key === null) {
            return false;
        }

        $query = $this->manager()
            ->query()
            ->deleteFrom(static::table())
            ->where(static::primaryKey(), '=', $key);

        $deleted = $this->manager()->delete($query->toSql(), $query->getBindings(), static::connectionName());
        if ($deleted < 1) {
            return false;
        }

        $this->exists = false;
        $this->setAttribute(static::primaryKey(), null);

        return true;
    }

    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): ModelQuery
    {
        $localKey ??= static::primaryKey();
        $foreignKey ??= $this->guessForeignKey(static::class, $localKey);

        return $this->relatedQuery($related)
            ->where($foreignKey, '=', $this->getAttribute($localKey))
            ->limit(1);
    }

    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): ModelQuery
    {
        $localKey ??= static::primaryKey();
        $foreignKey ??= $this->guessForeignKey(static::class, $localKey);

        return $this->relatedQuery($related)
            ->where($foreignKey, '=', $this->getAttribute($localKey));
    }

    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ModelQuery
    {
        $this->assertRelatedModel($related);

        $ownerKey ??= $related::primaryKey();
        $foreignKey ??= $this->guessForeignKey($related, $ownerKey);

        return $this->relatedQuery($related)
            ->where($ownerKey, '=', $this->getAttribute($foreignKey))
            ->limit(1);
    }

    protected static function newManager(): DatabaseManager
    {
        return new DatabaseManager();
    }

    protected function manager(): DatabaseManager
    {
        return $this->resolvedManager ??= static::sharedManager();
    }

    private static function sharedManager(): DatabaseManager
    {
        return self::$sharedManager ??= static::newManager();
    }

    private static function newQuery(?DatabaseManager $manager = null): ModelQuery
    {
        return new ModelQuery(
            static::class,
            $manager ?? static::sharedManager(),
            static::connectionName(),
        );
    }

    private static function metadata(): static
    {
        return new static();
    }

    private static function normalizeOptionalMetadata(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            throw new LogicException(sprintf('Model %s defines an empty metadata property.', static::class));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function declaredAttributes(): array
    {
        $attributes = [];

        foreach ($this->declaredProperties() as $name => $property) {
            $attributes[$name] = $property->isInitialized($this)
                ? $property->getValue($this)
                : null;
        }

        return $attributes;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function attributesForInsert(): array
    {
        return $this->filterPersistableAttributes($this->toArray());
    }

    /**
     * @return array<string, scalar|null>
     */
    private function attributesForUpdate(): array
    {
        $attributes = $this->toArray();
        unset($attributes[static::primaryKey()]);

        return $this->filterPersistableAttributes($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, scalar|null>
     */
    private function filterPersistableAttributes(array $attributes): array
    {
        $persisted = [];

        foreach ($attributes as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(DATE_ATOM);
            }

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $persisted[$key] = $value;
            }
        }

        return $persisted;
    }

    private function applyPersistenceHooks(): void
    {
        foreach (['applyTimestamps', 'applyBlameable'] as $method) {
            if (method_exists($this, $method)) {
                $this->{$method}();
            }
        }
    }

    private function relatedQuery(string $related): ModelQuery
    {
        $this->assertRelatedModel($related);

        return new ModelQuery($related, $this->manager());
    }

    private function assertRelatedModel(string $related): void
    {
        if ($related !== self::class && !is_subclass_of($related, self::class)) {
            throw new InvalidArgumentException(sprintf('Related model "%s" must extend %s.', $related, self::class));
        }
    }

    private function guessForeignKey(string $modelClass, string $keyName): string
    {
        $baseName = self::classBasename($modelClass);
        if (str_ends_with($baseName, 'Model') && $baseName !== 'Model') {
            $baseName = substr($baseName, 0, -5);
        }

        return sprintf('%s_%s', self::toSnakeCase($baseName), $keyName);
    }

    private static function classBasename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private static function toSnakeCase(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    private function hasDeclaredProperty(string $name): bool
    {
        return array_key_exists($name, $this->declaredProperties());
    }

    private function readDeclaredProperty(string $name): mixed
    {
        $property = $this->declaredProperties()[$name];

        return $property->isInitialized($this)
            ? $property->getValue($this)
            : null;
    }

    private function writeDeclaredProperty(string $name, mixed $value): void
    {
        $this->declaredProperties()[$name]->setValue($this, $value);
    }

    /**
     * @return array<string, ReflectionProperty>
     */
    private function declaredProperties(): array
    {
        return self::$declaredPropertyCache[static::class] ??= $this->buildDeclaredPropertyCache();
    }

    /**
     * @return array<string, ReflectionProperty>
     */
    private function buildDeclaredPropertyCache(): array
    {
        $properties = [];
        $seen = [];
        $reflection = new ReflectionObject($this);

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

    protected function internalPropertyNames(): array
    {
        return array_merge(
            self::INTERNAL_PROPERTIES,
            $this->traitInternalPropertyNames(),
        );
    }

    /**
     * @return list<string>
     */
    private function traitInternalPropertyNames(): array
    {
        $properties = [];

        foreach (['timestampInternalPropertyNames', 'blameableInternalPropertyNames'] as $method) {
            if (!method_exists($this, $method)) {
                continue;
            }

            /** @var list<string> $traitProperties */
            $traitProperties = $this->{$method}();
            $properties = array_merge($properties, $traitProperties);
        }

        return $properties;
    }

    private function isInternalProperty(ReflectionProperty $property): bool
    {
        return in_array($property->getName(), $this->internalPropertyNames(), true);
    }

    private function copyStateFrom(self $model): void
    {
        $this->attributes = $model->attributes;
        $this->exists = $model->exists;
        $this->resolvedManager = $model->resolvedManager;

        foreach ($model->declaredAttributes() as $name => $value) {
            if (!$this->hasDeclaredProperty($name)) {
                continue;
            }

            $this->writeDeclaredProperty($name, $value);
        }
    }
}
