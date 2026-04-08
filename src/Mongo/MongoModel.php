<?php

declare(strict_types=1);

namespace Myxa\Mongo;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use LogicException;
use Myxa\Database\Attributes\Internal;
use Myxa\Database\Model\HookEvent;
use Myxa\Database\Model\ModelMetadata;
use Myxa\Database\Model\ModelValueCaster;

abstract class MongoModel implements JsonSerializable
{
    private static ?MongoManager $sharedManager = null;

    #[Internal]
    protected string $collection = '';

    #[Internal]
    protected string $primaryKey = '_id';

    #[Internal]
    protected ?string $connection = null;

    /** @var array<string, mixed> */
    #[Internal]
    private array $attributes = [];

    #[Internal]
    private ?MongoManager $resolvedManager = null;

    #[Internal]
    private bool $exists = false;

    #[Internal]
    private bool $readOnly = false;

    /** @var array<string, mixed> */
    #[Internal]
    private array $original = [];

    /** @var array<string, mixed> */
    #[Internal]
    private array $changes = [];

    /**
     * @param array<string, mixed> $attributes
     */
    final public function __construct(array $attributes = [], ?MongoManager $manager = null)
    {
        $this->resolvedManager = $manager;
        $this->fill($attributes);
    }

    public function __clone()
    {
        $this->exists = false;
        $this->changes = [];
        $this->setAttribute(static::primaryKey(), null);
    }

    public static function setManager(MongoManager $manager): void
    {
        self::$sharedManager = $manager;
    }

    public static function clearManager(): void
    {
        self::$sharedManager = null;
    }

    public static function collection(): string
    {
        $collection = trim(static::metadata()->collection);
        if ($collection === '') {
            throw new LogicException(sprintf('Mongo model %s must define a non-empty $collection property.', static::class));
        }

        return $collection;
    }

    public static function primaryKey(): string
    {
        $primaryKey = trim(static::metadata()->primaryKey);
        if ($primaryKey === '') {
            throw new LogicException(sprintf('Mongo model %s must define a non-empty $primaryKey property.', static::class));
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

    public static function find(string|int $id): ?static
    {
        $document = static::sharedManager()
            ->collection(static::collection(), static::connectionName())
            ->findOne([static::primaryKey() => $id]);

        return $document === null ? null : static::hydrate($document);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function hydrate(array $attributes, ?MongoManager $manager = null): static
    {
        $model = new static([], $manager ?? static::sharedManager());
        $model->forceFill($attributes);
        $model->exists = true;
        $model->syncOriginal();

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

    public function getKey(): string|int|null
    {
        $key = $this->getAttribute(static::primaryKey());

        return is_string($key) || is_int($key) || $key === null ? $key : null;
    }

    /**
     * @return array<string, mixed>|mixed
     */
    public function getOriginal(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->original;
        }

        return $this->original[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $current = $this->currentPersistedState();
        $dirty = [];

        foreach ($current as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        foreach ($this->original as $key => $value) {
            if (!array_key_exists($key, $current)) {
                $dirty[$key] = null;
            }
        }

        return $dirty;
    }

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();

        if ($key === null) {
            return $dirty !== [];
        }

        return array_key_exists($key, $dirty);
    }

    public function wasChanged(?string $key = null): bool
    {
        if ($key === null) {
            return $this->changes !== [];
        }

        return array_key_exists($key, $this->changes);
    }

    /**
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

    public function getAttribute(string $key): mixed
    {
        if ($this->propertyMetadata()->hasDeclaredProperty($key)) {
            return $this->readDeclaredProperty($key);
        }

        return $this->attributes[$key] ?? null;
    }

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
        if ($this->propertyMetadata()->hasDeclaredProperty($name)) {
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
        $attributes = $this->allAttributes();
        $valueCaster = $this->valueCaster();

        foreach ($attributes as $key => $value) {
            $attributes[$key] = $this->normalizeDocumentValue($key, $value, $valueCaster);
        }

        foreach (array_keys($this->propertyMetadata()->hiddenProperties()) as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @throws JsonException
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR | $flags);
    }

    public function save(): bool
    {
        if ($this->readOnly) {
            return false;
        }

        $this->runHooks(HookEvent::BeforeSave);

        $document = $this->currentPersistedState();
        $collection = $this->manager()->collection(static::collection(), static::connectionName());

        if ($this->exists) {
            $this->runHooks(HookEvent::BeforeUpdate);

            $key = $this->getKey();
            if ($key === null) {
                throw new LogicException('Cannot update a persisted document without a primary key.');
            }

            $changes = $this->diffAttributes($document, $this->original);
            $updated = $collection->updateOne([static::primaryKey() => $key], $document);
            if (!$updated) {
                return false;
            }

            $this->changes = $changes;
            $this->runHooks(HookEvent::AfterUpdate);
            $this->runHooks(HookEvent::AfterSave);
            $this->syncOriginal();

            return true;
        }

        $insertedId = $collection->insertOne($document);
        if ($this->getKey() === null) {
            $this->setAttribute(static::primaryKey(), $insertedId);
        }

        $this->exists = true;
        $this->changes = $this->currentPersistedState();
        $this->runHooks(HookEvent::AfterSave);
        $this->syncOriginal();

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

        $this->runHooks(HookEvent::BeforeDelete);
        $this->changes = $this->original;

        $deleted = $this->manager()
            ->collection(static::collection(), static::connectionName())
            ->deleteOne([static::primaryKey() => $key]);

        if (!$deleted) {
            $this->changes = [];

            return false;
        }

        $this->exists = false;
        $this->runHooks(HookEvent::AfterDelete);
        $this->setAttribute(static::primaryKey(), null);

        return true;
    }

    protected static function newManager(): MongoManager
    {
        return new MongoManager();
    }

    protected function manager(): MongoManager
    {
        return $this->resolvedManager ??= static::sharedManager();
    }

    private static function sharedManager(): MongoManager
    {
        return self::$sharedManager ??= static::newManager();
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
     * @return array<string, mixed>
     */
    private function declaredAttributes(): array
    {
        $attributes = [];
        foreach ($this->propertyMetadata()->declaredProperties() as $name => $property) {
            $attributes[$name] = $property->isInitialized($this)
                ? $property->getValue($this)
                : null;
        }

        return $attributes;
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

        return $property->isInitialized($this) ? $property->getValue($this) : null;
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

    /**
     * @return array<string, mixed>
     */
    private function currentPersistedState(): array
    {
        $document = [];
        $valueCaster = $this->valueCaster();

        foreach ($this->allAttributes() as $key => $value) {
            $document[$key] = $this->normalizeDocumentValue($key, $value, $valueCaster);
        }

        return $document;
    }

    private function syncOriginal(): void
    {
        $this->original = $this->currentPersistedState();
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $original
     * @return array<string, mixed>
     */
    private function diffAttributes(array $attributes, array $original): array
    {
        $changes = [];

        foreach ($attributes as $key => $value) {
            if (!array_key_exists($key, $original) || $original[$key] !== $value) {
                $changes[$key] = $value;
            }
        }

        return $changes;
    }

    private function runHooks(HookEvent $event): void
    {
        foreach ($this->propertyMetadata()->hookMethodsFor($event) as $method) {
            $method->invoke($this);
        }
    }

    private function normalizeDocumentValue(string $key, mixed $value, ModelValueCaster $valueCaster): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        $value = $valueCaster->serializeAttributeValue($key, $value);

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $nestedKey => $nestedValue) {
                $normalized[$nestedKey] = $this->normalizeDocumentValue($key, $nestedValue, $valueCaster);
            }

            return $normalized;
        }

        return $value;
    }
}
