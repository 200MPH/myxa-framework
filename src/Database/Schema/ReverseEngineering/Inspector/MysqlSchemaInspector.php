<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering\Inspector;

use Myxa\Database\Schema\ReverseEngineering\ForeignKeySchema;
use Myxa\Database\Schema\ReverseEngineering\IndexSchema;
use Myxa\Database\Schema\ReverseEngineering\TableSchema;

final class MysqlSchemaInspector extends AbstractSchemaInspector
{
    public function table(string $table): TableSchema
    {
        $schema = $this->currentDatabase();
        $columns = [];
        $indexes = [];
        $foreignKeys = [];

        foreach ($this->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE '
            . 'FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ORDINAL_POSITION ASC',
            [$schema, $table],
        ) as $column) {
            $columnType = (string) $column['COLUMN_TYPE'];
            $extra = strtolower((string) ($column['EXTRA'] ?? ''));

            $columns[] = $this->makeColumn(
                name: (string) $column['COLUMN_NAME'],
                databaseType: (string) $column['DATA_TYPE'],
                nullable: (string) $column['IS_NULLABLE'] === 'YES',
                defaultValue: $column['COLUMN_DEFAULT'] ?? null,
                unsigned: str_contains($columnType, 'unsigned'),
                autoIncrement: str_contains($extra, 'auto_increment'),
                primary: (string) ($column['COLUMN_KEY'] ?? '') === 'PRI',
                length: isset($column['CHARACTER_MAXIMUM_LENGTH']) ? (int) $column['CHARACTER_MAXIMUM_LENGTH'] : null,
                precision: isset($column['NUMERIC_PRECISION']) ? (int) $column['NUMERIC_PRECISION'] : null,
                scale: isset($column['NUMERIC_SCALE']) ? (int) $column['NUMERIC_SCALE'] : null,
            );
        }

        foreach ($this->select(
            'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX '
            . 'FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? ORDER BY INDEX_NAME ASC, SEQ_IN_INDEX ASC',
            [$schema, $table],
        ) as $index) {
            $name = (string) $index['INDEX_NAME'];
            $type = $name === 'PRIMARY'
                ? IndexSchema::TYPE_PRIMARY
                : ((int) $index['NON_UNIQUE'] === 0 ? IndexSchema::TYPE_UNIQUE : IndexSchema::TYPE_INDEX);

            if (!isset($indexes[$name])) {
                $indexes[$name] = new IndexSchema($name, $type, []);
            }

            $columnsForIndex = $indexes[$name]->columns();
            $columnsForIndex[] = (string) $index['COLUMN_NAME'];
            $indexes[$name] = new IndexSchema($name, $type, $columnsForIndex);
        }

        foreach ($this->select(
            'SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.UPDATE_RULE, rc.DELETE_RULE, kcu.ORDINAL_POSITION '
            . 'FROM information_schema.key_column_usage kcu '
            . 'JOIN information_schema.referential_constraints rc '
            . 'ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME '
            . 'WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL '
            . 'ORDER BY kcu.CONSTRAINT_NAME ASC, kcu.ORDINAL_POSITION ASC',
            [$schema, $table],
        ) as $foreignKey) {
            $name = (string) $foreignKey['CONSTRAINT_NAME'];
            $entry = $foreignKeys[$name] ?? [
                'columns' => [],
                'referencedTable' => (string) $foreignKey['REFERENCED_TABLE_NAME'],
                'referencedColumns' => [],
                'onDelete' => strtoupper((string) $foreignKey['DELETE_RULE']),
                'onUpdate' => strtoupper((string) $foreignKey['UPDATE_RULE']),
            ];
            $entry['columns'][] = (string) $foreignKey['COLUMN_NAME'];
            $entry['referencedColumns'][] = (string) $foreignKey['REFERENCED_COLUMN_NAME'];
            $foreignKeys[$name] = $entry;
        }

        return new TableSchema(
            $table,
            $columns,
            array_values($indexes),
            array_map(
                static fn (string $name, array $foreignKey): ForeignKeySchema => new ForeignKeySchema(
                    $name,
                    $foreignKey['columns'],
                    $foreignKey['referencedTable'],
                    $foreignKey['referencedColumns'],
                    $foreignKey['onDelete'],
                    $foreignKey['onUpdate'],
                ),
                array_keys($foreignKeys),
                array_values($foreignKeys),
            ),
        );
    }

    public function tables(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['TABLE_NAME'],
            $this->select(
                'SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = ? AND table_type = \'BASE TABLE\' ORDER BY TABLE_NAME ASC',
                [$this->currentDatabase()],
            ),
        );
    }

    private function currentDatabase(): string
    {
        $rows = $this->select('SELECT DATABASE() AS name');

        return (string) ($rows[0]['name'] ?? '');
    }
}
