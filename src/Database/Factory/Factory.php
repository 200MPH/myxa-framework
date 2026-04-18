<?php

declare(strict_types=1);

namespace Myxa\Database\Factory;

use InvalidArgumentException;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Model\Model;

/**
 * Small base class for model factories backed by FakeData.
 *
 * @phpstan-consistent-constructor
 */
abstract class Factory
{
    /** @var list<array<string, mixed>|callable(array<string, mixed>, FakeData): array<string, mixed>>> */
    private array $states = [];

    private int $count = 1;

    public function __construct(
        protected FakeData $faker = new FakeData(),
        protected ?DatabaseManager $manager = null,
    ) {
    }

    /**
     * Create a new factory instance.
     */
    public static function new(?FakeData $faker = null, ?DatabaseManager $manager = null): static
    {
        return new static($faker ?? new FakeData(), $manager);
    }

    /**
     * Return the target model class for this factory.
     *
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    /**
     * Return the default attributes for a model instance.
     *
     * @return array<string, mixed>
     */
    abstract protected function definition(): array;

    /**
     * Return the fake data generator used by the factory.
     */
    public function faker(): FakeData
    {
        return $this->faker;
    }

    /**
     * Return a clone that uses the given database manager.
     */
    public function withManager(?DatabaseManager $manager): static
    {
        $factory = clone $this;
        $factory->manager = $manager;

        return $factory;
    }

    /**
     * Return a clone that will build the given number of models.
     */
    public function count(int $count): static
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Factory count must be at least 1.');
        }

        $factory = clone $this;
        $factory->count = $count;

        return $factory;
    }

    /**
     * Return a clone with an extra state transformation.
     *
     * @param array<string, mixed>|callable(array<string, mixed>, FakeData): array<string, mixed> $state
     */
    public function state(array|callable $state): static
    {
        $factory = clone $this;
        $factory->states[] = $state;

        return $factory;
    }

    /**
     * Return the raw attribute payload.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function raw(array $attributes = []): array
    {
        if ($this->count === 1) {
            return $this->buildAttributes($attributes);
        }

        $rows = [];

        for ($index = 0; $index < $this->count; $index++) {
            $rows[] = $this->buildAttributes($attributes);
        }

        return $rows;
    }

    /**
     * Build unsaved model instances.
     *
     * @param array<string, mixed> $attributes
     * @return Model|list<Model>
     */
    public function make(array $attributes = []): Model|array
    {
        if ($this->count === 1) {
            return $this->makeOne($attributes);
        }

        $models = [];

        for ($index = 0; $index < $this->count; $index++) {
            $models[] = $this->makeOne($attributes);
        }

        return $models;
    }

    /**
     * Build and persist model instances.
     *
     * @param array<string, mixed> $attributes
     * @return Model|list<Model>
     */
    public function create(array $attributes = []): Model|array
    {
        if ($this->count === 1) {
            return $this->createOne($attributes);
        }

        $models = [];

        for ($index = 0; $index < $this->count; $index++) {
            $models[] = $this->createOne($attributes);
        }

        return $models;
    }

    /**
     * Hook for adjusting a model after make().
     */
    protected function afterMaking(Model $model): void
    {
    }

    /**
     * Hook for adjusting a model after create().
     */
    protected function afterCreating(Model $model): void
    {
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function buildAttributes(array $attributes): array
    {
        $payload = $this->definition();

        foreach ($this->states as $state) {
            $resolved = is_array($state)
                ? $state
                : $state($payload, $this->faker);

            if (!is_array($resolved)) {
                throw new InvalidArgumentException('Factory state callbacks must return an attribute array.');
            }

            $payload = array_replace($payload, $resolved);
        }

        return array_replace($payload, $attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeOne(array $attributes): Model
    {
        $modelClass = $this->model();
        $model = new $modelClass($this->buildAttributes($attributes), $this->manager);
        $this->afterMaking($model);

        return $model;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createOne(array $attributes): Model
    {
        $model = $this->makeOne($attributes);
        $model->save();
        $this->afterCreating($model);

        return $model;
    }
}
