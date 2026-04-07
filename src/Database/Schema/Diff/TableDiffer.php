<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\Diff;

use InvalidArgumentException;
use Myxa\Database\Schema\ReverseEngineering\ColumnSchema;
use Myxa\Database\Schema\ReverseEngineering\ForeignKeySchema;
use Myxa\Database\Schema\ReverseEngineering\IndexSchema;
use Myxa\Database\Schema\ReverseEngineering\TableSchema;

/**
 * Compare two normalized table definitions.
 */
final class TableDiffer
{
    /**
     * Compare two versions of the same table.
     */
    public function diff(TableSchema $from, TableSchema $to): TableDiff
    {
        if ($from->name() !== $to->name()) {
            throw new InvalidArgumentException(sprintf(
                'Cannot diff table "%s" against "%s".',
                $from->name(),
                $to->name(),
            ));
        }

        return new TableDiff(
            table: $from->name(),
            addedColumns: $this->addedColumns($from, $to),
            droppedColumns: $this->addedColumns($to, $from),
            changedColumns: $this->changedColumns($from, $to),
            addedIndexes: $this->addedIndexes($from, $to),
            droppedIndexes: $this->addedIndexes($to, $from),
            addedForeignKeys: $this->addedForeignKeys($from, $to),
            droppedForeignKeys: $this->addedForeignKeys($to, $from),
        );
    }

    /**
     * @return list<ColumnSchema>
     */
    private function addedColumns(TableSchema $from, TableSchema $to): array
    {
        $fromColumns = $this->mapColumns($from->columns());
        $added = [];

        foreach ($to->columns() as $column) {
            if (!isset($fromColumns[$column->name()])) {
                $added[] = $column;
            }
        }

        return $added;
    }

    /**
     * @return list<ColumnChange>
     */
    private function changedColumns(TableSchema $from, TableSchema $to): array
    {
        $fromColumns = $this->mapColumns($from->columns());
        $changes = [];

        foreach ($to->columns() as $column) {
            $original = $fromColumns[$column->name()] ?? null;
            if (!$original instanceof ColumnSchema) {
                continue;
            }

            if ($this->columnSignature($original) !== $this->columnSignature($column)) {
                $changes[] = new ColumnChange($original, $column);
            }
        }

        return $changes;
    }

    /**
     * @return list<IndexSchema>
     */
    private function addedIndexes(TableSchema $from, TableSchema $to): array
    {
        $fromIndexes = $this->mapIndexes($from->indexes());
        $added = [];

        foreach ($to->indexes() as $index) {
            $original = $fromIndexes[$index->name()] ?? null;
            if (!$original instanceof IndexSchema || $this->indexSignature($original) !== $this->indexSignature($index)) {
                $added[] = $index;
            }
        }

        return $added;
    }

    /**
     * @return list<ForeignKeySchema>
     */
    private function addedForeignKeys(TableSchema $from, TableSchema $to): array
    {
        $fromForeignKeys = $this->mapForeignKeys($from->foreignKeys());
        $added = [];

        foreach ($to->foreignKeys() as $foreignKey) {
            $original = $fromForeignKeys[$foreignKey->name()] ?? null;
            if (
                !$original instanceof ForeignKeySchema
                || $this->foreignKeySignature($original) !== $this->foreignKeySignature($foreignKey)
            ) {
                $added[] = $foreignKey;
            }
        }

        return $added;
    }

    /**
     * @param list<ColumnSchema> $columns
     * @return array<string, ColumnSchema>
     */
    private function mapColumns(array $columns): array
    {
        $mapped = [];

        foreach ($columns as $column) {
            $mapped[$column->name()] = $column;
        }

        return $mapped;
    }

    /**
     * @param list<IndexSchema> $indexes
     * @return array<string, IndexSchema>
     */
    private function mapIndexes(array $indexes): array
    {
        $mapped = [];

        foreach ($indexes as $index) {
            $mapped[$index->name()] = $index;
        }

        return $mapped;
    }

    /**
     * @param list<ForeignKeySchema> $foreignKeys
     * @return array<string, ForeignKeySchema>
     */
    private function mapForeignKeys(array $foreignKeys): array
    {
        $mapped = [];

        foreach ($foreignKeys as $foreignKey) {
            $mapped[$foreignKey->name()] = $foreignKey;
        }

        return $mapped;
    }

    private function columnSignature(ColumnSchema $column): string
    {
        return json_encode([
            'type' => $column->type(),
            'nullable' => $column->isNullable(),
            'default' => $column->defaultValue(),
            'hasDefault' => $column->hasDefault(),
            'unsigned' => $column->isUnsigned(),
            'autoIncrement' => $column->isAutoIncrement(),
            'primary' => $column->isPrimary(),
            'options' => $column->options(),
        ], JSON_THROW_ON_ERROR);
    }

    private function indexSignature(IndexSchema $index): string
    {
        return json_encode([
            'type' => $index->type(),
            'columns' => $index->columns(),
        ], JSON_THROW_ON_ERROR);
    }

    private function foreignKeySignature(ForeignKeySchema $foreignKey): string
    {
        return json_encode([
            'columns' => $foreignKey->columns(),
            'referencedTable' => $foreignKey->referencedTable(),
            'referencedColumns' => $foreignKey->referencedColumns(),
            'onDelete' => $foreignKey->onDelete(),
            'onUpdate' => $foreignKey->onUpdate(),
        ], JSON_THROW_ON_ERROR);
    }
}
