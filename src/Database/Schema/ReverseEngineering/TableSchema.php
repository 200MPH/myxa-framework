<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering;

/**
 * Normalized schema metadata for a database table.
 */
final readonly class TableSchema
{
    /**
     * @param list<ColumnSchema> $columns
     * @param list<IndexSchema> $indexes
     * @param list<ForeignKeySchema> $foreignKeys
     */
    public function __construct(
        private string $name,
        private array $columns,
        private array $indexes,
        private array $foreignKeys,
    ) {
    }

    /**
     * Return the table name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<ColumnSchema>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<IndexSchema>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @return list<ForeignKeySchema>
     */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }
}
