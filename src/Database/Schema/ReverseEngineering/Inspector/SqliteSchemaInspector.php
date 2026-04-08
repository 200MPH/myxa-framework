<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering\Inspector;

use Myxa\Database\Schema\ReverseEngineering\ForeignKeySchema;
use Myxa\Database\Schema\ReverseEngineering\IndexSchema;
use Myxa\Database\Schema\ReverseEngineering\TableSchema;

final class SqliteSchemaInspector extends AbstractSchemaInspector
{
    public function table(string $table): TableSchema
    {
        $columns = [];
        $indexes = [];
        $foreignKeys = [];

        $primaryColumns = [];
        foreach ($this->select(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $table))) as $column) {
            $primary = (int) ($column['pk'] ?? 0) > 0;
            if ($primary) {
                $primaryColumns[] = (string) $column['name'];
            }

            $type = (string) ($column['type'] ?? 'TEXT');
            $autoIncrement = $primary && str_contains(strtoupper($type), 'INT');

            $columns[] = $this->makeColumn(
                name: (string) $column['name'],
                databaseType: $type,
                nullable: $primary ? false : ((int) ($column['notnull'] ?? 0) !== 1),
                defaultValue: $column['dflt_value'] ?? null,
                autoIncrement: $autoIncrement,
                primary: $primary,
                length: $this->extractLength($type),
                precision: $this->extractPrecision($type),
                scale: $this->extractScale($type),
            );
        }

        if ($primaryColumns !== []) {
            $indexes[] = new IndexSchema('primary', IndexSchema::TYPE_PRIMARY, $primaryColumns);
        }

        foreach ($this->select(sprintf('PRAGMA index_list("%s")', str_replace('"', '""', $table))) as $index) {
            if ((string) ($index['origin'] ?? '') === 'pk') {
                continue;
            }

            $columnsForIndex = [];
            $indexInfo = sprintf(
                'PRAGMA index_info("%s")',
                str_replace('"', '""', (string) $index['name']),
            );

            foreach ($this->select($indexInfo) as $item) {
                $columnsForIndex[] = (string) $item['name'];
            }

            $indexes[] = new IndexSchema(
                name: (string) $index['name'],
                type: (int) ($index['unique'] ?? 0) === 1 ? IndexSchema::TYPE_UNIQUE : IndexSchema::TYPE_INDEX,
                columns: $columnsForIndex,
            );
        }

        $fkGroups = [];
        $foreignKeyList = sprintf('PRAGMA foreign_key_list("%s")', str_replace('"', '""', $table));

        foreach ($this->select($foreignKeyList) as $foreignKey) {
            $id = (string) ($foreignKey['id'] ?? '0');
            $fkGroups[$id]['table'] = (string) $foreignKey['table'];
            $fkGroups[$id]['on_delete'] = (string) ($foreignKey['on_delete'] ?? '');
            $fkGroups[$id]['on_update'] = (string) ($foreignKey['on_update'] ?? '');
            $fkGroups[$id]['columns'][] = (string) $foreignKey['from'];
            $fkGroups[$id]['references'][] = (string) $foreignKey['to'];
        }

        foreach ($fkGroups as $id => $foreignKey) {
            $foreignKeys[] = new ForeignKeySchema(
                name: sprintf('%s_fk_%s', $table, $id),
                columns: $foreignKey['columns'],
                referencedTable: $foreignKey['table'],
                referencedColumns: $foreignKey['references'],
                onDelete: $foreignKey['on_delete'] !== '' ? strtoupper($foreignKey['on_delete']) : null,
                onUpdate: $foreignKey['on_update'] !== '' ? strtoupper($foreignKey['on_update']) : null,
            );
        }

        return new TableSchema($table, $columns, $indexes, $foreignKeys);
    }

    public function tables(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC",
            ),
        );
    }

    private function extractLength(string $type): ?int
    {
        if (preg_match('/\((\d+)(?:,\s*\d+)?\)/', $type, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function extractPrecision(string $type): ?int
    {
        if (preg_match('/\((\d+),\s*(\d+)\)/', $type, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function extractScale(string $type): ?int
    {
        if (preg_match('/\((\d+),\s*(\d+)\)/', $type, $matches) !== 1) {
            return null;
        }

        return (int) $matches[2];
    }
}
