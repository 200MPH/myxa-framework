<?php

declare(strict_types=1);

namespace Myxa\Database\Query;

use Closure;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Lightweight SQL query builder.
 */
final class QueryBuilder
{
    private const string TYPE_SELECT = 'select';

    private const string TYPE_INSERT = 'insert';

    private const string TYPE_UPDATE = 'update';

    private const string TYPE_DELETE = 'delete';

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

    private string $statementType = self::TYPE_SELECT;

    /** @var list<string> */
    private array $selectColumns = ['*'];

    private ?string $table = null;

    /** @var list<string> */
    private array $whereClauses = [];

    /** @var list<scalar|null> */
    private array $whereBindings = [];

    /** @var list<string> */
    private array $insertColumns = [];

    /** @var list<scalar|null> */
    private array $insertBindings = [];

    /** @var list<string> */
    private array $updateAssignments = [];

    /** @var list<scalar|null> */
    private array $updateBindings = [];

    /** @var list<string> */
    private array $orderByColumns = [];

    /** @var list<string> */
    private array $groupByColumns = [];

    /** @var list<string> */
    private array $joinClauses = [];

    /** @var list<scalar|null> */
    private array $joinBindings = [];

    private ?int $limitValue = null;

    private int $offsetValue = 0;

    public function select(string ...$columns): self
    {
        $this->beginStatement(self::TYPE_SELECT);
        $this->selectColumns = $columns === [] ? ['*'] : array_values($columns);

        return $this;
    }

    public function from(string $table, ?string $database = null): self
    {
        $this->beginStatement(self::TYPE_SELECT);
        $this->table = $this->qualifyTable($table, $database);

        return $this;
    }

    public function insertInto(string $table, ?string $database = null): self
    {
        $this->beginStatement(self::TYPE_INSERT);
        $this->table = $this->qualifyTable($table, $database);

        return $this;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function values(#[SensitiveParameter] array $values): self
    {
        $this->ensureStatementType(self::TYPE_INSERT, 'VALUES can only be used with INSERT queries.');

        if ($values === []) {
            throw new InvalidArgumentException('Values for INSERT cannot be empty.');
        }

        $this->insertColumns = [];
        $this->insertBindings = [];

        foreach ($values as $column => $value) {
            if (!is_string($column) || trim($column) === '') {
                throw new InvalidArgumentException('Insert column names must be non-empty strings.');
            }

            $this->insertColumns[] = $this->quoteIdentifier($column);
            $this->insertBindings[] = $this->normalizeBindingValue($value);
        }

        return $this;
    }

    public function update(string $table, ?string $database = null): self
    {
        $this->beginStatement(self::TYPE_UPDATE);
        $this->table = $this->qualifyTable($table, $database);

        return $this;
    }

    public function set(string $column, #[SensitiveParameter] mixed $value): self
    {
        $this->ensureStatementType(self::TYPE_UPDATE, 'SET can only be used with UPDATE queries.');

        $this->updateAssignments[] = sprintf('%s = ?', $this->quoteIdentifier($column));
        $this->updateBindings[] = $this->normalizeBindingValue($value);

        return $this;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function setMany(#[SensitiveParameter] array $values): self
    {
        $this->ensureStatementType(self::TYPE_UPDATE, 'SET can only be used with UPDATE queries.');

        if ($values === []) {
            throw new InvalidArgumentException('Values for UPDATE cannot be empty.');
        }

        foreach ($values as $column => $value) {
            if (!is_string($column) || trim($column) === '') {
                throw new InvalidArgumentException('Update column names must be non-empty strings.');
            }

            $this->set($column, $value);
        }

        return $this;
    }

    public function deleteFrom(string $table, ?string $database = null): self
    {
        $this->beginStatement(self::TYPE_DELETE);
        $this->table = $this->qualifyTable($table, $database);

        return $this;
    }

    public function where(string $column, string $operator, #[SensitiveParameter] mixed $value): self
    {
        $this->ensureStatementTypes(
            [self::TYPE_SELECT, self::TYPE_UPDATE, self::TYPE_DELETE],
            'WHERE clauses cannot be used with INSERT queries.',
        );

        $normalizedOperator = strtoupper(trim($operator));
        if (!in_array($normalizedOperator, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported operator "%s".', $operator));
        }

        $this->whereClauses[] = sprintf(
            '%s %s ?',
            $this->quoteIdentifier($column),
            $normalizedOperator,
        );
        $this->whereBindings[] = $this->normalizeBindingValue($value);

        return $this;
    }

    public function whereBetween(
        string $column,
        #[SensitiveParameter] mixed $from,
        #[SensitiveParameter] mixed $to,
    ): self {
        $this->ensureStatementTypes(
            [self::TYPE_SELECT, self::TYPE_UPDATE, self::TYPE_DELETE],
            'WHERE clauses cannot be used with INSERT queries.',
        );

        $this->whereClauses[] = sprintf('%s BETWEEN ? AND ?', $this->quoteIdentifier($column));
        $this->whereBindings[] = $this->normalizeBindingValue($from);
        $this->whereBindings[] = $this->normalizeBindingValue($to);

        return $this;
    }

    /**
     * @param list<scalar|null> $values
     */
    public function whereIn(string $column, #[SensitiveParameter] array $values): self
    {
        $this->ensureStatementTypes(
            [self::TYPE_SELECT, self::TYPE_UPDATE, self::TYPE_DELETE],
            'WHERE clauses cannot be used with INSERT queries.',
        );

        if ($values === []) {
            throw new InvalidArgumentException('Values for WHERE IN cannot be empty.');
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->whereClauses[] = sprintf('%s IN (%s)', $this->quoteIdentifier($column), $placeholders);

        foreach ($values as $value) {
            $this->whereBindings[] = $this->normalizeBindingValue($value);
        }

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->ensureStatementType(self::TYPE_SELECT, 'ORDER BY can only be used with SELECT queries.');

        $normalizedDirection = strtoupper(trim($direction));
        if ($normalizedDirection !== 'ASC' && $normalizedDirection !== 'DESC') {
            throw new InvalidArgumentException(sprintf('Unsupported order direction "%s".', $direction));
        }

        $this->orderByColumns[] = sprintf('%s %s', $this->quoteIdentifier($column), $normalizedDirection);

        return $this;
    }

    public function groupBy(string $column): self
    {
        $this->ensureStatementType(self::TYPE_SELECT, 'GROUP BY can only be used with SELECT queries.');
        $this->groupByColumns[] = $this->quoteIdentifier($column);

        return $this;
    }

    public function join(string $table, Closure|string $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->addJoin('INNER', $table, $first, $operator, $second);
    }

    public function leftJoin(string $table, Closure|string $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->addJoin('LEFT', $table, $first, $operator, $second);
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->ensureStatementType(self::TYPE_SELECT, 'LIMIT can only be used with SELECT queries.');

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
        return match ($this->statementType) {
            self::TYPE_SELECT => $this->buildSelectSql(),
            self::TYPE_INSERT => $this->buildInsertSql(),
            self::TYPE_UPDATE => $this->buildUpdateSql(),
            self::TYPE_DELETE => $this->buildDeleteSql(),
            default => throw new LogicException(sprintf('Unsupported statement type "%s".', $this->statementType)),
        };
    }

    public function debugQuery(): string
    {
        return SqlInterpolator::interpolate($this->toSql(), $this->getBindings());
    }

    public function reset(): self
    {
        $this->statementType = self::TYPE_SELECT;
        $this->selectColumns = ['*'];
        $this->table = null;
        $this->whereClauses = [];
        $this->whereBindings = [];
        $this->insertColumns = [];
        $this->insertBindings = [];
        $this->updateAssignments = [];
        $this->updateBindings = [];
        $this->orderByColumns = [];
        $this->groupByColumns = [];
        $this->joinClauses = [];
        $this->joinBindings = [];
        $this->limitValue = null;
        $this->offsetValue = 0;

        return $this;
    }

    /**
     * @return list<scalar|null>
     */
    public function getBindings(): array
    {
        return match ($this->statementType) {
            self::TYPE_SELECT => array_values(array_merge($this->joinBindings, $this->whereBindings)),
            self::TYPE_DELETE => $this->whereBindings,
            self::TYPE_INSERT => $this->insertBindings,
            self::TYPE_UPDATE => array_values(array_merge($this->updateBindings, $this->whereBindings)),
            default => [],
        };
    }

    private function buildSelectSql(): string
    {
        if ($this->table === null) {
            throw new LogicException('FROM table is required before generating SQL.');
        }

        $sql = sprintf('SELECT %s FROM %s', $this->buildSelectColumns(), $this->table);

        if ($this->joinClauses !== []) {
            $sql .= ' ' . implode(' ', $this->joinClauses);
        }

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

    private function buildInsertSql(): string
    {
        if ($this->table === null) {
            throw new LogicException('INSERT table is required before generating SQL.');
        }

        if ($this->insertColumns === []) {
            throw new LogicException('VALUES are required before generating INSERT SQL.');
        }

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $this->insertColumns),
            implode(', ', array_fill(0, count($this->insertBindings), '?')),
        );
    }

    private function buildUpdateSql(): string
    {
        if ($this->table === null) {
            throw new LogicException('UPDATE table is required before generating SQL.');
        }

        if ($this->updateAssignments === []) {
            throw new LogicException('SET values are required before generating UPDATE SQL.');
        }

        $sql = sprintf('UPDATE %s SET %s', $this->table, implode(', ', $this->updateAssignments));

        if ($this->whereClauses !== []) {
            $sql .= sprintf(' WHERE %s', implode(' AND ', $this->whereClauses));
        }

        return $sql;
    }

    private function buildDeleteSql(): string
    {
        if ($this->table === null) {
            throw new LogicException('DELETE table is required before generating SQL.');
        }

        $sql = sprintf('DELETE FROM %s', $this->table);

        if ($this->whereClauses !== []) {
            $sql .= sprintf(' WHERE %s', implode(' AND ', $this->whereClauses));
        }

        return $sql;
    }

    private function buildSelectColumns(): string
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

    private function qualifyTable(string $table, ?string $database = null): string
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

        if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $table, $matches) === 1) {
            $baseTable = trim($matches[1]);
            $alias = trim($matches[2]);
            $qualified = $database !== null
                ? sprintf('%s.%s', $this->quoteIdentifier($database), $this->quoteIdentifier($baseTable))
                : $this->quoteIdentifier($baseTable);

            return sprintf('%s AS %s', $qualified, $this->quoteIdentifier($alias));
        }

        return $database !== null
            ? sprintf('%s.%s', $this->quoteIdentifier($database), $this->quoteIdentifier($table))
            : $this->quoteIdentifier($table);
    }

    private function beginStatement(string $statementType): void
    {
        if ($this->statementType === $statementType) {
            return;
        }

        if (!$this->isPristine()) {
            throw new LogicException(sprintf(
                'Cannot switch builder from %s to %s without calling reset().',
                strtoupper($this->statementType),
                strtoupper($statementType),
            ));
        }

        $this->statementType = $statementType;
    }

    private function ensureStatementType(string $statementType, string $message): void
    {
        if ($this->statementType !== $statementType) {
            throw new LogicException($message);
        }
    }

    /**
     * @param list<string> $statementTypes
     */
    private function ensureStatementTypes(array $statementTypes, string $message): void
    {
        if (!in_array($this->statementType, $statementTypes, true)) {
            throw new LogicException($message);
        }
    }

    private function isPristine(): bool
    {
        return $this->statementType === self::TYPE_SELECT
            && $this->selectColumns === ['*']
            && $this->table === null
            && $this->whereClauses === []
            && $this->whereBindings === []
            && $this->insertColumns === []
            && $this->insertBindings === []
            && $this->updateAssignments === []
            && $this->updateBindings === []
            && $this->orderByColumns === []
            && $this->groupByColumns === []
            && $this->joinClauses === []
            && $this->joinBindings === []
            && $this->limitValue === null
            && $this->offsetValue === 0;
    }

    private function addJoin(
        string $type,
        string $table,
        Closure|string $first,
        ?string $operator,
        ?string $second,
    ): self
    {
        $this->ensureStatementType(self::TYPE_SELECT, 'JOIN can only be used with SELECT queries.');

        $this->joinClauses[] = sprintf(
            '%s JOIN %s ON %s',
            $type,
            $this->qualifyTable($table),
            $first instanceof Closure
                ? $this->buildJoinClause($first)
                : $this->buildJoinComparison($first, $operator, $second),
        );

        return $this;
    }

    private function buildJoinClause(Closure $callback): string
    {
        $join = new JoinClause(
            $this->quoteIdentifier(...),
            $this->normalizeComparisonOperator(...),
            $this->normalizeBindingValue(...),
        );

        $callback($join);

        $this->joinBindings = [...$this->joinBindings, ...$join->getBindings()];

        return $join->toSql();
    }

    private function buildJoinComparison(string $first, ?string $operator, ?string $second): string
    {
        if ($operator === null || $second === null) {
            throw new InvalidArgumentException('JOIN comparisons require first column, operator, and second column.');
        }

        return sprintf(
            '%s %s %s',
            $this->quoteIdentifier($first),
            $this->normalizeComparisonOperator($operator),
            $this->quoteIdentifier($second),
        );
    }

    private function normalizeComparisonOperator(string $operator): string
    {
        $normalizedOperator = strtoupper(trim($operator));
        if (!in_array($normalizedOperator, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported operator "%s".', $operator));
        }

        return $normalizedOperator;
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
