<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use InvalidArgumentException;
use JsonSerializable;
use JsonException;
use LogicException;
use Myxa\Database\Attributes\Internal;
use Myxa\Database\DatabaseManager;

/**
 * Minimal active-record style model built on top of DatabaseManager.
 */
abstract class Model implements JsonSerializable
{
    private static ?DatabaseManager $sharedManager = null;

    #[Internal]
    protected string $table = '';

    #[Internal]
    protected string $primaryKey = 'id';

    #[Internal]
    protected ?string $connection = null;

    /** @var array<string, mixed> */
    #[Internal]
    private array $attributes = [];

    /** @var array<string, mixed> */
    #[Internal]
    private array $relations = [];

    #[Internal]
    private ?DatabaseManager $resolvedManager = null;

    #[Internal]
    private bool $exists = false;

    #[Internal]
    private bool $readOnly = false;

    /**
     * Create a new model instance and optionally prefill declared attributes.
     *
     * @param array<string, mixed> $attributes
     */
    final public function __construct(array $attributes = [], ?DatabaseManager $manager = null)
    {
        $this->resolvedManager = $manager;
        $this->fill($attributes);
    }

    /**
     * Clone the model into a new unsaved instance with a cleared primary key.
     */
    public function __clone()
    {
        $this->exists = false;
        $this->relations = [];
        $this->setAttribute(static::primaryKey(), null);
    }

    /**
     * Set the shared database manager used by models without an injected manager.
     */
    public static function setManager(DatabaseManager $manager): void
    {
        self::$sharedManager = $manager;
    }

    /**
     * Clear the shared database manager so a new one will be resolved lazily.
     */
    public static function clearManager(): void
    {
        self::$sharedManager = null;
    }

    /**
     * Return the table name configured for the model.
     */
    public static function table(): string
    {
        $table = trim(static::metadata()->table);
        if ($table === '') {
            throw new LogicException(sprintf('Model %s must define a non-empty $table property.', static::class));
        }

        return $table;
    }

    /**
     * Return the primary key column name configured for the model.
     */
    public static function primaryKey(): string
    {
        $primaryKey = trim(static::metadata()->primaryKey);
        if ($primaryKey === '') {
            throw new LogicException(sprintf('Model %s must define a non-empty $primaryKey property.', static::class));
        }

        return $primaryKey;
    }

    /**
     * Return the optional connection alias configured for the model.
     */
    public static function connectionName(): ?string
    {
        return static::normalizeOptionalMetadata(static::metadata()->connection);
    }

    /**
     * Build a new unsaved model instance.
     *
     * @param array<string, mixed> $attributes
     */
    public static function make(array $attributes = []): static
    {
        return new static($attributes);
    }

    /**
     * Create, persist, and return a new model instance.
     *
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * Start a new query builder scoped to this model class.
     */
    public static function query(): ModelQuery
    {
        return static::newQuery();
    }

    /**
     * Retrieve all model records with optional pagination.
     *
     * @return list<static>
     */
    public static function all(?int $limit = null, int $offset = 0): array
    {
        return static::newQuery()
            ->limit($limit, $offset)
            ->get();
    }

    /**
     * Find a model by its primary key or return null when missing.
     */
    public static function find(int|string $id): ?static
    {
        return static::newQuery()->find($id);
    }

    /**
     * Find a model by its primary key or throw when no record exists.
     */
    public static function findOrFail(int|string $id): static
    {
        return static::newQuery()->findOrFail($id);
    }

    /**
     * Hydrate a persisted model instance from trusted storage data.
     *
     * @param array<string, mixed> $attributes
     */
    public static function hydrate(array $attributes, ?DatabaseManager $manager = null): static
    {
        $model = new static([], $manager ?? static::sharedManager());
        $model->forceFill($attributes);
        $model->exists = true;

        return $model;
    }

    /**
     * Determine whether the model currently represents a persisted record.
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Determine whether write operations are currently disabled for the model.
     */
    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * Enable or disable read-only mode for the current model instance.
     */
    public function setReadOnly(bool $readOnly = true): static
    {
        $this->readOnly = $readOnly;

        return $this;
    }

    /**
     * Return the current primary key value for the model.
     */
    public function getKey(): int|string|null
    {
        return $this->getAttribute(static::primaryKey());
    }

    /**
     * Mass-assign declared, non-guarded attributes onto the model.
     *
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        $metadata = $this->propertyMetadata();

        foreach ($attributes as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Model attribute keys must be non-empty strings.');
            }

            if (!$metadata->hasDeclaredProperty($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot mass-assign unknown attribute "%s" on model %s.',
                    $key,
                    static::class,
                ));
            }

            if ($metadata->isGuardedProperty($key)) {
                continue;
            }

            $this->writeAttributeValue($key, $value);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Model attribute keys must be non-empty strings.');
            }

            $this->writeAttributeValue($key, $value);
        }

        return $this;
    }

    /**
     * Read a declared model property or previously hydrated storage attribute.
     */
    public function getAttribute(string $key): mixed
    {
        if ($this->propertyMetadata()->hasDeclaredProperty($key)) {
            return $this->readDeclaredProperty($key);
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        return $this->attributes[$key] ?? null;
    }

    /**
     * Assign a known model attribute, including loaded primary key values.
     */
    public function setAttribute(string $key, mixed $value): static
    {
        if (!$this->canWriteAttribute($key)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot set unknown attribute "%s" on model %s.',
                $key,
                static::class,
            ));
        }

        $this->writeAttributeValue($key, $value);

        return $this;
    }

    /**
     * Proxy dynamic property reads to model attributes.
     */
    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    /**
     * Proxy dynamic property writes to model attributes.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Determine whether a model attribute currently resolves to a non-null value.
     */
    public function __isset(string $name): bool
    {
        return $this->getAttribute($name) !== null;
    }

    /**
     * Clear a declared property or hydrated attribute from the model instance.
     */
    public function __unset(string $name): void
    {
        if ($this->propertyMetadata()->hasDeclaredProperty($name)) {
            $this->writeDeclaredProperty($name, null);

            return;
        }

        if (array_key_exists($name, $this->attributes)) {
            unset($this->attributes[$name]);
        }
    }

    /**
     * Convert the model into an array suitable for API output or JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = $this->allAttributes();
        $valueCaster = $this->valueCaster();

        foreach ($attributes as $key => $value) {
            $attributes[$key] = $valueCaster->serializeAttributeValue($key, $value);
        }

        foreach (array_keys($this->propertyMetadata()->hiddenProperties()) as $key) {
            unset($attributes[$key]);
        }

        foreach ($this->relations as $name => $relation) {
            $attributes[$name] = $this->serializeRelationValue($relation);
        }

        return $attributes;
    }

    /**
     * Determine whether a relation has already been loaded on the model.
     */
    public function relationLoaded(string $relation): bool
    {
        return array_key_exists($relation, $this->relations);
    }

    /**
     * Return a previously loaded relation value.
     */
    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation] ?? null;
    }

    /**
     * Store an already loaded relation value on the model instance.
     */
    public function setRelation(string $relation, mixed $value): static
    {
        $this->relations[$relation] = $value;

        return $this;
    }

    /**
     * Return the serializable payload used by json_encode().
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Encode the model into a JSON string.
     *
     * @throws JsonException
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR | $flags);
    }

    /**
     * Reload the model from storage and replace the current in-memory state.
     */
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

    /**
     * Insert or update the model in storage depending on its persisted state.
     */
    public function save(): bool
    {
        if ($this->readOnly) {
            return false;
        }

        $this->applyPersistenceHooks();
        $this->runHooks(HookEvent::BeforeSave);

        if ($this->exists) {
            $this->runHooks(HookEvent::BeforeUpdate);

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
            $this->runHooks(HookEvent::AfterUpdate);
            $this->runHooks(HookEvent::AfterSave);

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
        $this->runHooks(HookEvent::AfterSave);

        return true;
    }

    /**
     * Delete the persisted model record and clear its in-memory key.
     */
    public function delete(): bool
    {
        if ($this->readOnly || !$this->exists) {
            return false;
        }

        $key = $this->getKey();
        if ($key === null) {
            return false;
        }

        $this->runHooks(HookEvent::BeforeDelete);

        $query = $this->manager()
            ->query()
            ->deleteFrom(static::table())
            ->where(static::primaryKey(), '=', $key);

        $deleted = $this->manager()->delete($query->toSql(), $query->getBindings(), static::connectionName());
        if ($deleted < 1) {
            return false;
        }

        $this->exists = false;
        $this->runHooks(HookEvent::AfterDelete);
        $this->setAttribute(static::primaryKey(), null);

        return true;
    }

    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): Relation
    {
        $this->assertRelatedModel($related);
        $localKey ??= static::primaryKey();
        $foreignKey ??= $this->guessForeignKey(static::class, $localKey);

        return new HasOneRelation(
            $this,
            $related,
            $this->manager(),
            $related::connectionName(),
            $foreignKey,
            $localKey,
        );
    }

    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): Relation
    {
        $this->assertRelatedModel($related);
        $localKey ??= static::primaryKey();
        $foreignKey ??= $this->guessForeignKey(static::class, $localKey);

        return new HasManyRelation(
            $this,
            $related,
            $this->manager(),
            $related::connectionName(),
            $foreignKey,
            $localKey,
        );
    }

    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): Relation
    {
        $this->assertRelatedModel($related);

        $ownerKey ??= $related::primaryKey();
        $foreignKey ??= $this->guessForeignKey($related, $ownerKey);

        return new BelongsToRelation(
            $this,
            $related,
            $this->manager(),
            $related::connectionName(),
            $foreignKey,
            $ownerKey,
        );
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
        $declaredProperties = $this->propertyMetadata()->declaredProperties();

        foreach ($declaredProperties as $name => $property) {
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
        return $this->filterPersistableAttributes($this->allAttributes());
    }

    /**
     * @return array<string, scalar|null>
     */
    private function attributesForUpdate(): array
    {
        $attributes = $this->allAttributes();
        unset($attributes[static::primaryKey()]);

        return $this->filterPersistableAttributes($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function allAttributes(): array
    {
        $attributes = $this->declaredAttributes();
        $primaryKey = static::primaryKey();

        if (!array_key_exists($primaryKey, $attributes) && array_key_exists($primaryKey, $this->attributes)) {
            $attributes[$primaryKey] = $this->attributes[$primaryKey];
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, scalar|null>
     */
    private function filterPersistableAttributes(array $attributes): array
    {
        $persisted = [];
        $valueCaster = $this->valueCaster();

        foreach ($attributes as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $value = $valueCaster->serializeAttributeValue($key, $value);

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

    private function runHooks(HookEvent $event): void
    {
        foreach ($this->propertyMetadata()->hookMethodsFor($event) as $method) {
            $method->invoke($this);
        }
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
        return $this->propertyMetadata()->hasDeclaredProperty($name);
    }

    private function canWriteAttribute(string $name): bool
    {
        return $this->hasDeclaredProperty($name)
            || $name === static::primaryKey()
            || array_key_exists($name, $this->attributes);
    }

    private function readDeclaredProperty(string $name): mixed
    {
        $property = $this->propertyMetadata()->declaredProperties()[$name];

        return $property->isInitialized($this)
            ? $property->getValue($this)
            : null;
    }

    private function writeDeclaredProperty(string $name, mixed $value): void
    {
        $this->propertyMetadata()->declaredProperties()[$name]->setValue($this, $value);
    }

    private function writeAttributeValue(string $name, mixed $value): void
    {
        if ($this->propertyMetadata()->hasDeclaredProperty($name)) {
            $this->writeDeclaredProperty($name, $this->valueCaster()->castAttributeValue($name, $value));

            return;
        }

        $this->attributes[$name] = $value;
    }

    private function propertyMetadata(): ModelMetadata
    {
        return new ModelMetadata($this);
    }

    private function valueCaster(): ModelValueCaster
    {
        return new ModelValueCaster($this->propertyMetadata(), static::class);
    }

    private function copyStateFrom(self $model): void
    {
        $this->attributes = $model->attributes;
        $this->relations = $model->relations;
        $this->exists = $model->exists;
        $this->resolvedManager = $model->resolvedManager;

        foreach ($model->declaredAttributes() as $name => $value) {
            if (!$this->hasDeclaredProperty($name)) {
                continue;
            }

            $this->writeDeclaredProperty($name, $value);
        }
    }

    private function serializeRelationValue(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->serializeRelationValue($item), $value);
        }

        return $value;
    }
}
