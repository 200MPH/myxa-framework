<?php

declare(strict_types=1);

namespace Myxa\Database;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Lightweight SQL SELECT query builder.
 */
final class QueryBuilder
{
    private const array ALLOWED_OPERATORS = [
        '=',
        '!=',
        '<>',
        '<',
        '<=',
        '>',
        '>=',
        'LIKE',
        'NOT LIKE',
    ];

    /** @var list<string> */
    private array $selectColumns = ['*'];

    private ?string $from = null;

    /** @var list<string> */
    private array $whereClauses = [];

    /** @var list<scalar|null> */
    private array $bindings = [];

    /** @var list<string> */
    private array $orderByColumns = [];

    /** @var list<string> */
    private array $groupByColumns = [];

    private ?int $limitValue = null;

    private int $offsetValue = 0;

    public function select(string ...$columns): self
    {
        $this->selectColumns = $columns === [] ? ['*'] : array_values($columns);

        return $this;
    }

    public function from(string $table, ?string $database = null): self
    {
        $table = trim($table);
        if ($table === '') {
            throw new InvalidArgumentException('Table name cannot be empty.');
        }

        if ($database !== null) {
            $database = trim($database);
            if ($database === '') {
                throw new InvalidArgumentException('Database name cannot be empty when provided.');
            }
        }

        $this->from = $database !== null
            ? sprintf('%s.%s', $this->quoteIdentifier($database), $this->quoteIdentifier($table))
            : $this->quoteIdentifier($table);

        return $this;
    }

    public function where(string $column, string $operator, #[SensitiveParameter] mixed $value): self
    {
        $normalizedOperator = strtoupper(trim($operator));
        if (!in_array($normalizedOperator, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported operator "%s".', $operator));
        }

        $this->whereClauses[] = sprintf(
            '%s %s ?',
            $this->quoteIdentifier($column),
            $normalizedOperator,
        );
        $this->bindings[] = $this->normalizeBindingValue($value);

        return $this;
    }

    public function whereBetween(
        string $column,
        #[SensitiveParameter] mixed $from,
        #[SensitiveParameter] mixed $to,
    ): self {
        $this->whereClauses[] = sprintf('%s BETWEEN ? AND ?', $this->quoteIdentifier($column));
        $this->bindings[] = $this->normalizeBindingValue($from);
        $this->bindings[] = $this->normalizeBindingValue($to);

        return $this;
    }

    /**
     * @param list<scalar|null> $values
     */
    public function whereIn(string $column, #[SensitiveParameter] array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('Values for WHERE IN cannot be empty.');
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->whereClauses[] = sprintf('%s IN (%s)', $this->quoteIdentifier($column), $placeholders);

        foreach ($values as $value) {
            $this->bindings[] = $this->normalizeBindingValue($value);
        }

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $normalizedDirection = strtoupper(trim($direction));
        if ($normalizedDirection !== 'ASC' && $normalizedDirection !== 'DESC') {
            throw new InvalidArgumentException(sprintf('Unsupported order direction "%s".', $direction));
        }

        $this->orderByColumns[] = sprintf('%s %s', $this->quoteIdentifier($column), $normalizedDirection);

        return $this;
    }

    public function groupBy(string $column): self
    {
        $this->groupByColumns[] = $this->quoteIdentifier($column);

        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be greater than 0.');
        }

        if ($offset < 0) {
            throw new InvalidArgumentException('Offset cannot be negative.');
        }

        $this->limitValue = $limit;
        $this->offsetValue = $offset;

        return $this;
    }

    public function toSql(): string
    {
        if ($this->from === null) {
            throw new LogicException('FROM table is required before generating SQL.');
        }

        $sql = sprintf('SELECT %s FROM %s', $this->buildSelect(), $this->from);

        if ($this->whereClauses !== []) {
            $sql .= sprintf(' WHERE %s', implode(' AND ', $this->whereClauses));
        }

        if ($this->groupByColumns !== []) {
            $sql .= sprintf(' GROUP BY %s', implode(', ', $this->groupByColumns));
        }

        if ($this->orderByColumns !== []) {
            $sql .= sprintf(' ORDER BY %s', implode(', ', $this->orderByColumns));
        }

        if ($this->limitValue !== null) {
            $sql .= sprintf(' LIMIT %d', $this->limitValue);
            if ($this->offsetValue > 0) {
                $sql .= sprintf(' OFFSET %d', $this->offsetValue);
            }
        }

        return $sql;
    }

    public function debugQuery(): string
    {
        return SqlInterpolator::interpolate($this->toSql(), $this->bindings);
    }

    public function reset(): self
    {
        $this->selectColumns = ['*'];
        $this->from = null;
        $this->whereClauses = [];
        $this->bindings = [];
        $this->orderByColumns = [];
        $this->groupByColumns = [];
        $this->limitValue = null;
        $this->offsetValue = 0;

        return $this;
    }

    /**
     * @return list<scalar|null>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    private function buildSelect(): string
    {
        $columns = array_map($this->normalizeSelectColumn(...), $this->selectColumns);

        return implode(', ', $columns);
    }

    private function normalizeSelectColumn(string $column): string
    {
        $column = trim($column);
        if ($column === '') {
            throw new InvalidArgumentException('Select column cannot be empty.');
        }

        if ($column === '*') {
            return $column;
        }

        if (str_ends_with($column, '.*')) {
            return $this->quoteIdentifier(substr($column, 0, -2)) . '.*';
        }

        return $this->quoteIdentifier($column);
    }

    /**
     * @return scalar|null
     */
    private function normalizeBindingValue(mixed $value): string|int|float|bool|null
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('Binding value must be a scalar or null.');
    }

    private function quoteIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new InvalidArgumentException('Identifier cannot be empty.');
        }

        if (str_contains($identifier, '`')) {
            throw new InvalidArgumentException('Identifier cannot contain backticks.');
        }

        $parts = explode('.', $identifier);
        $quotedParts = array_map(
            static function (string $part): string {
                $part = trim($part);
                if ($part === '') {
                    throw new InvalidArgumentException('Identifier contains an empty segment.');
                }

                return sprintf('`%s`', $part);
            },
            $parts,
        );

        return implode('.', $quotedParts);
    }
}
