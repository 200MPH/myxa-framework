<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use Myxa\Database\DatabaseManager;
use Myxa\Database\Query\QueryBuilder;

/**
 * Read-focused query helper for models.
 *
 * @template TModel of Model
 */
final class ModelQuery
{
    private QueryBuilder $query;

    /**
     * @param class-string<TModel> $modelClass
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly DatabaseManager $manager,
        private readonly ?string $connection = null,
    ) {
        $this->query = (new QueryBuilder())->from($this->modelClass::table());
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
        $rows = $this->manager->select($this->query->toSql(), $this->query->getBindings(), $this->connection);

        return array_map(
            fn (array $row): Model => $this->hydrateRow($row),
            $rows,
        );
    }

    public function first(): ?Model
    {
        $query = clone $this->query;
        $query->limit(1);

        $rows = $this->manager->select($query->toSql(), $query->getBindings(), $this->connection);
        if ($rows === []) {
            return null;
        }

        return $this->hydrateRow($rows[0]);
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

        return $this->hydrateRow($rows[0]);
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
}
