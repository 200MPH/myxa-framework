<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering;

use Myxa\Database\Schema\Blueprint;
use Myxa\Database\Schema\IndexDefinition;

/**
 * Convert a captured schema blueprint into normalized table metadata.
 */
final class BlueprintTableSchemaFactory
{
    /**
     * Build a normalized table definition from a blueprint.
     */
    public function fromBlueprint(Blueprint $blueprint): TableSchema
    {
        $columns = [];
        foreach ($blueprint->columns() as $column) {
            $columns[] = new ColumnSchema(
                name: $column->name(),
                type: $column->type(),
                nullable: $column->isNullable(),
                defaultValue: $column->defaultValue(),
                hasDefault: $column->hasDefault(),
                unsigned: $column->isUnsigned(),
                autoIncrement: $column->isAutoIncrement(),
                primary: $column->isPrimary(),
                options: $column->options(),
            );
        }

        $indexes = [];
        foreach ($blueprint->indexes() as $index) {
            $indexes[] = new IndexSchema(
                name: $index->name(),
                type: $this->normalizeIndexType($index),
                columns: $index->columns(),
            );
        }

        $foreignKeys = [];
        foreach ($blueprint->foreignKeys() as $foreignKey) {
            $foreignKeys[] = new ForeignKeySchema(
                name: $foreignKey->name(),
                columns: $foreignKey->columns(),
                referencedTable: $foreignKey->table(),
                referencedColumns: $foreignKey->referencedColumns(),
                onDelete: $foreignKey->deleteAction(),
                onUpdate: $foreignKey->updateAction(),
            );
        }

        return new TableSchema($blueprint->tableName(), $columns, $indexes, $foreignKeys);
    }

    private function normalizeIndexType(IndexDefinition $index): string
    {
        return match ($index->type()) {
            IndexDefinition::TYPE_PRIMARY => IndexSchema::TYPE_PRIMARY,
            IndexDefinition::TYPE_UNIQUE => IndexSchema::TYPE_UNIQUE,
            default => IndexSchema::TYPE_INDEX,
        };
    }
}
