<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering;

/**
 * Normalized schema metadata for a foreign key constraint.
 */
final readonly class ForeignKeySchema
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        private string $name,
        private array $columns,
        private string $referencedTable,
        private array $referencedColumns,
        private ?string $onDelete = null,
        private ?string $onUpdate = null,
    ) {
    }

    /**
     * Return the foreign key name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Return the referenced table.
     */
    public function referencedTable(): string
    {
        return $this->referencedTable;
    }

    /**
     * @return list<string>
     */
    public function referencedColumns(): array
    {
        return $this->referencedColumns;
    }

    /**
     * Return the ON DELETE action, if available.
     */
    public function onDelete(): ?string
    {
        return $this->onDelete;
    }

    /**
     * Return the ON UPDATE action, if available.
     */
    public function onUpdate(): ?string
    {
        return $this->onUpdate;
    }
}
