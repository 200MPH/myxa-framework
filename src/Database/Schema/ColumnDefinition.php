<?php

declare(strict_types=1);

namespace Myxa\Database\Schema;

use InvalidArgumentException;
use Myxa\Database\Query\RawExpression;

/**
 * Fluent definition for a single schema column.
 */
final class ColumnDefinition
{
    private bool $nullable = false;

    private bool $unsigned = false;

    private bool $autoIncrement = false;

    private bool $primary = false;

    private bool $hasDefault = false;

    private mixed $default = null;

    /**
     * @param array<string, int> $options
     */
    public function __construct(
        private readonly Blueprint $blueprint,
        private readonly string $name,
        private readonly string $type,
        private readonly array $options = [],
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Column name cannot be empty.');
        }

        if (trim($this->type) === '') {
            throw new InvalidArgumentException('Column type cannot be empty.');
        }
    }

    /**
     * Mark the column as nullable or not nullable.
     */
    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;

        return $this;
    }

    /**
     * Set the column default value.
     */
    public function default(mixed $value): self
    {
        if (
            !$value instanceof RawExpression
            && !is_scalar($value)
            && $value !== null
        ) {
            throw new InvalidArgumentException('Schema default value must be scalar, null, or a raw expression.');
        }

        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    /**
     * Use the current timestamp as the default value.
     */
    public function useCurrent(): self
    {
        return $this->default(new RawExpression('CURRENT_TIMESTAMP'));
    }

    /**
     * Mark the numeric column as unsigned when supported by the driver.
     */
    public function unsigned(bool $value = true): self
    {
        $this->unsigned = $value;

        return $this;
    }

    /**
     * Mark the column as auto-incrementing.
     */
    public function autoIncrement(bool $value = true): self
    {
        $this->autoIncrement = $value;

        if ($value) {
            $this->nullable = false;
        }

        return $this;
    }

    /**
     * Mark the column as part of the primary key.
     */
    public function primary(bool $value = true): self
    {
        $this->primary = $value;

        if ($value) {
            $this->nullable = false;
        }

        return $this;
    }

    /**
     * Add a unique index for this column.
     */
    public function unique(?string $name = null): self
    {
        $this->blueprint->unique($this->name, $name);

        return $this;
    }

    /**
     * Add a non-unique index for this column.
     */
    public function index(?string $name = null): self
    {
        $this->blueprint->index($this->name, $name);

        return $this;
    }

    /**
     * Return the column name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the logical column type.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, int>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * Determine whether the column is nullable.
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Determine whether the column is unsigned.
     */
    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    /**
     * Determine whether the column auto-increments.
     */
    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    /**
     * Determine whether the column is part of the primary key.
     */
    public function isPrimary(): bool
    {
        return $this->primary;
    }

    /**
     * Determine whether a default value was explicitly defined.
     */
    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    /**
     * Return the configured default value.
     */
    public function defaultValue(): mixed
    {
        return $this->default;
    }
}
