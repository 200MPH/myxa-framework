<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\Diff;

use Myxa\Database\Schema\ReverseEngineering\ColumnSchema;
use Myxa\Database\Schema\ReverseEngineering\ForeignKeySchema;
use Myxa\Database\Schema\ReverseEngineering\IndexSchema;

/**
 * Normalized schema differences between two versions of one table.
 */
final readonly class TableDiff
{
    /**
     * @param list<ColumnSchema> $addedColumns
     * @param list<ColumnSchema> $droppedColumns
     * @param list<ColumnChange> $changedColumns
     * @param list<IndexSchema> $addedIndexes
     * @param list<IndexSchema> $droppedIndexes
     * @param list<ForeignKeySchema> $addedForeignKeys
     * @param list<ForeignKeySchema> $droppedForeignKeys
     */
    public function __construct(
        private string $table,
        private array $addedColumns,
        private array $droppedColumns,
        private array $changedColumns,
        private array $addedIndexes,
        private array $droppedIndexes,
        private array $addedForeignKeys,
        private array $droppedForeignKeys,
    ) {
    }

    /**
     * Return the table name.
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * @return list<ColumnSchema>
     */
    public function addedColumns(): array
    {
        return $this->addedColumns;
    }

    /**
     * @return list<ColumnSchema>
     */
    public function droppedColumns(): array
    {
        return $this->droppedColumns;
    }

    /**
     * @return list<ColumnChange>
     */
    public function changedColumns(): array
    {
        return $this->changedColumns;
    }

    /**
     * @return list<IndexSchema>
     */
    public function addedIndexes(): array
    {
        return $this->addedIndexes;
    }

    /**
     * @return list<IndexSchema>
     */
    public function droppedIndexes(): array
    {
        return $this->droppedIndexes;
    }

    /**
     * @return list<ForeignKeySchema>
     */
    public function addedForeignKeys(): array
    {
        return $this->addedForeignKeys;
    }

    /**
     * @return list<ForeignKeySchema>
     */
    public function droppedForeignKeys(): array
    {
        return $this->droppedForeignKeys;
    }

    /**
     * Determine whether the diff contains any changes.
     */
    public function hasChanges(): bool
    {
        return $this->addedColumns !== []
            || $this->droppedColumns !== []
            || $this->changedColumns !== []
            || $this->addedIndexes !== []
            || $this->droppedIndexes !== []
            || $this->addedForeignKeys !== []
            || $this->droppedForeignKeys !== [];
    }

    /**
     * Determine whether the diff contains unsupported column modifications.
     */
    public function hasChangedColumns(): bool
    {
        return $this->changedColumns !== [];
    }
}
