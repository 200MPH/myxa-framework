<?php

declare(strict_types=1);

namespace Myxa\Database\Query;

use LogicException;

/**
 * Build JOIN ON clauses with chained AND conditions.
 */
final class JoinClause
{
    /** @var list<string> */
    private array $conditions = [];

    /** @var list<scalar|null> */
    private array $bindings = [];

    /**
     * @param callable(string): string $quoteIdentifier
     * @param callable(string): string $normalizeOperator
     * @param callable(mixed): scalar|null $normalizeBindingValue
     */
    public function __construct(
        private readonly \Closure $quoteIdentifier,
        private readonly \Closure $normalizeOperator,
        private readonly \Closure $normalizeBindingValue,
    ) {
    }

    public function on(string $first, string $operator, string $second): self
    {
        $this->conditions[] = sprintf(
            '%s %s %s',
            ($this->quoteIdentifier)($first),
            ($this->normalizeOperator)($operator),
            ($this->quoteIdentifier)($second),
        );

        return $this;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $this->conditions[] = sprintf(
            '%s %s ?',
            ($this->quoteIdentifier)($column),
            ($this->normalizeOperator)($operator),
        );
        $this->bindings[] = ($this->normalizeBindingValue)($value);

        return $this;
    }

    public function toSql(): string
    {
        if ($this->conditions === []) {
            throw new LogicException('JOIN clauses require at least one ON condition.');
        }

        return implode(' AND ', $this->conditions);
    }

    /**
     * @return list<scalar|null>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
}
