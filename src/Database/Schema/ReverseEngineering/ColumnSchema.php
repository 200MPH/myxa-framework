<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering;

/**
 * Normalized schema metadata for a database column.
 */
final readonly class ColumnSchema
{
    /**
     * @param array<string, int> $options
     */
    public function __construct(
        private string $name,
        private string $type,
        private bool $nullable = false,
        private mixed $defaultValue = null,
        private bool $hasDefault = false,
        private bool $unsigned = false,
        private bool $autoIncrement = false,
        private bool $primary = false,
        private array $options = [],
    ) {
    }

    /**
     * Return the column name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the normalized Myxa schema type.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Determine whether the column is nullable.
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Return the normalized default value.
     */
    public function defaultValue(): mixed
    {
        return $this->defaultValue;
    }

    /**
     * Determine whether the column has an explicit default value.
     */
    public function hasDefault(): bool
    {
        return $this->hasDefault;
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
     * @return array<string, int>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Return a single normalized type option.
     */
    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }
}
