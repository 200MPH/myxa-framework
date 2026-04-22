<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use Closure;
use Generator;
use InvalidArgumentException;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Model\Exceptions\ModelNotFoundException;
use Myxa\Database\Query\QueryBuilder;

/**
 * Read-focused query helper for models.
 *
 * @template TModel of Model
 */
class ModelQuery
{
    private QueryBuilder $query;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $with = [];

    /**
     * @param class-string<TModel> $modelClass
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly DatabaseManager $manager,
        private readonly ?string $connection = null,
    ) {
        $this->query = $this->manager->query($this->connection)->from($this->modelClass::table());
    }

    public function select(string ...$columns): self
    {
        $this->query->select(...$columns);

        return $this;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $this->query->where($column, $operator, $value);

        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): self
    {
        $this->query->whereBetween($column, $from, $to);

        return $this;
    }

    /**
     * @param list<scalar|null> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $this->query->whereIn($column, $values);

        return $this;
    }

    public function whereKey(int|string $id): self
    {
        $this->query->where($this->modelClass::primaryKey(), '=', $id);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->query->orderBy($column, $direction);

        return $this;
    }

    public function join(string $table, Closure|string $first, ?string $operator = null, ?string $second = null): self
    {
        $this->query->join($table, $first, $operator, $second);

        return $this;
    }

    public function leftJoin(
        string $table,
        Closure|string $first,
        ?string $operator = null,
        ?string $second = null,
    ): self {
        $this->query->leftJoin($table, $first, $operator, $second);

        return $this;
    }

    /**
     * @param string|list<string> ...$relations
     */
    public function with(string|array ...$relations): self
    {
        foreach ($this->normalizeRelations($relations) as $relation) {
            $this->addWithPath($relation);
        }

        return $this;
    }

    public function limit(?int $limit, int $offset = 0): self
    {
        if ($limit !== null) {
            $this->query->limit($limit, $offset);
        }

        return $this;
    }

    /**
     * @return list<Model>
     */
    public function get(): array
    {
        return $this->getUsingQuery($this->query);
    }

    /**
     * @return Generator<int, Model, void, void>
     */
    public function cursor(): Generator
    {
        $rows = $this->manager->cursor($this->query->toSql(), $this->query->getBindings(), $this->connection);

        foreach ($rows as $row) {
            $model = $this->hydrateRow($row);
            $this->eagerLoadRelations([$model]);

            yield $model;
        }
    }

    /**
     * @param callable(list<Model>, int): (bool|null) $callback
     */
    public function chunk(int $size, callable $callback): bool
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Chunk size must be greater than 0.');
        }

        $offset = 0;
        $page = 1;

        do {
            $query = clone $this->query;
            $query->limit($size, $offset);

            $models = $this->getUsingQuery($query);
            if ($models === []) {
                return true;
            }

            if ($callback($models, $page) === false) {
                return false;
            }

            $offset += $size;
            $page++;
        } while (count($models) === $size);

        return true;
    }

    /**
     * @return list<Model>
     */
    private function getUsingQuery(QueryBuilder $query): array
    {
        $rows = $this->manager->select($query->toSql(), $query->getBindings(), $this->connection);
        $models = array_map(
            fn (array $row): Model => $this->hydrateRow($row),
            $rows,
        );

        $this->eagerLoadRelations($models);

        return $models;
    }

    public function first(): ?Model
    {
        $query = clone $this->query;
        $query->limit(1);

        $rows = $this->manager->select($query->toSql(), $query->getBindings(), $this->connection);
        if ($rows === []) {
            return null;
        }

        $model = $this->hydrateRow($rows[0]);
        $this->eagerLoadRelations([$model]);

        return $model;
    }

    public function firstOrFail(): Model
    {
        return $this->first() ?? throw ModelNotFoundException::forModel($this->modelClass);
    }

    public function find(int|string $id): ?Model
    {
        $query = clone $this->query;
        $query
            ->where($this->modelClass::primaryKey(), '=', $id)
            ->limit(1);

        $rows = $this->manager->select($query->toSql(), $query->getBindings(), $this->connection);
        if ($rows === []) {
            return null;
        }

        $model = $this->hydrateRow($rows[0]);
        $this->eagerLoadRelations([$model]);

        return $model;
    }

    public function findOrFail(int|string $id): Model
    {
        return $this->find($id) ?? throw ModelNotFoundException::forKey($this->modelClass, $id);
    }

    public function exists(): bool
    {
        return $this->first() !== null;
    }

    public function toSql(): string
    {
        return $this->query->toSql();
    }

    /**
     * @return list<scalar|null>
     */
    public function getBindings(): array
    {
        return $this->query->getBindings();
    }

    private function hydrateRow(array $row): Model
    {
        return $this->modelClass::hydrate($row, $this->manager);
    }

    /**
     * @param list<Model> $models
     */
    private function eagerLoadRelations(array $models): void
    {
        if ($models === [] || $this->with === []) {
            return;
        }

        foreach ($this->with as $relation => $nested) {
            $loader = $this->resolveRelation($models[0], $relation);
            $relatedModels = $loader->eagerLoad($models, $relation);

            if ($relatedModels !== [] && $nested !== []) {
                $nestedQuery = new self(
                    $loader->relatedModelClass(),
                    $this->manager,
                    $loader->relatedModelClass()::connectionName(),
                );
                $nestedQuery->setWithTree($nested)->eagerLoadRelations($relatedModels);
            }
        }
    }

    /**
     * @param list<string|list<string>> $relations
     * @return list<string>
     */
    private function normalizeRelations(array $relations): array
    {
        $normalized = [];

        foreach ($relations as $relation) {
            if (is_array($relation)) {
                foreach ($relation as $item) {
                    if (is_string($item)) {
                        $normalized[] = $item;
                    }
                }

                continue;
            }

            $normalized[] = $relation;
        }

        return $normalized;
    }

    private function addWithPath(string $path): void
    {
        $segments = array_values(array_filter(
            array_map('trim', explode('.', $path)),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            return;
        }

        $tree = &$this->with;

        foreach ($segments as $segment) {
            $tree[$segment] ??= [];
            $tree = &$tree[$segment];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $with
     */
    private function setWithTree(array $with): self
    {
        $this->with = $with;

        return $this;
    }

    private function resolveRelation(Model $model, string $relation): Relation
    {
        if (!method_exists($model, $relation)) {
            throw new InvalidArgumentException(sprintf(
                'Relation "%s" is not defined on model %s.',
                $relation,
                $model::class,
            ));
        }

        $resolved = $model->{$relation}();

        if (!$resolved instanceof Relation) {
            throw new InvalidArgumentException(sprintf(
                'Relation "%s" on model %s must return %s.',
                $relation,
                $model::class,
                Relation::class,
            ));
        }

        return $resolved;
    }
}
