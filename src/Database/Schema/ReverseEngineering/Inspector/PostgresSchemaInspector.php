<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering\Inspector;

use Myxa\Database\Schema\ReverseEngineering\ForeignKeySchema;
use Myxa\Database\Schema\ReverseEngineering\IndexSchema;
use Myxa\Database\Schema\ReverseEngineering\TableSchema;

final class PostgresSchemaInspector extends AbstractSchemaInspector
{
    public function table(string $table): TableSchema
    {
        $columns = [];
        $indexes = [];
        $foreignKeys = [];

        $columnRows = $this->select(
            'SELECT c.column_name, c.data_type, c.is_nullable, c.column_default, '
            . 'c.character_maximum_length, c.numeric_precision, c.numeric_scale, '
            . 'COALESCE(tc.constraint_type = \'PRIMARY KEY\', false) AS is_primary '
            . 'FROM information_schema.columns c '
            . 'LEFT JOIN information_schema.key_column_usage kcu '
            . 'ON kcu.table_schema = c.table_schema '
            . 'AND kcu.table_name = c.table_name '
            . 'AND kcu.column_name = c.column_name '
            . 'LEFT JOIN information_schema.table_constraints tc '
            . 'ON tc.table_schema = kcu.table_schema '
            . 'AND tc.table_name = kcu.table_name '
            . 'AND tc.constraint_name = kcu.constraint_name '
            . 'AND tc.constraint_type = \'PRIMARY KEY\' '
            . 'WHERE c.table_schema = current_schema() AND c.table_name = ? '
            . 'ORDER BY c.ordinal_position ASC',
            [$table],
        );

        foreach ($columnRows as $column) {
            $default = $column['column_default'] ?? null;
            $autoIncrement = is_string($default) && str_contains($default, 'nextval(');

            $columns[] = $this->makeColumn(
                name: (string) $column['column_name'],
                databaseType: (string) $column['data_type'],
                nullable: (string) $column['is_nullable'] === 'YES',
                defaultValue: $autoIncrement ? null : $default,
                autoIncrement: $autoIncrement,
                primary: filter_var($column['is_primary'], FILTER_VALIDATE_BOOL),
                length: isset($column['character_maximum_length'])
                    ? (int) $column['character_maximum_length']
                    : null,
                precision: isset($column['numeric_precision'])
                    ? (int) $column['numeric_precision']
                    : null,
                scale: isset($column['numeric_scale']) ? (int) $column['numeric_scale'] : null,
            );
        }

        foreach (
            $this->select(
                'SELECT tc.constraint_name, tc.constraint_type, '
                . 'array_agg(kcu.column_name ORDER BY kcu.ordinal_position) AS columns '
                . 'FROM information_schema.table_constraints tc '
                . 'JOIN information_schema.key_column_usage kcu '
                . 'ON kcu.table_schema = tc.table_schema '
                . 'AND kcu.table_name = tc.table_name '
                . 'AND kcu.constraint_name = tc.constraint_name '
                . 'WHERE tc.table_schema = current_schema() AND tc.table_name = ? '
                . 'AND tc.constraint_type IN (\'PRIMARY KEY\', \'UNIQUE\') '
                . 'GROUP BY tc.constraint_name, tc.constraint_type',
                [$table],
            ) as $index
        ) {
            $indexes[] = new IndexSchema(
                name: (string) $index['constraint_name'],
                type: (string) $index['constraint_type'] === 'PRIMARY KEY'
                    ? IndexSchema::TYPE_PRIMARY
                    : IndexSchema::TYPE_UNIQUE,
                columns: $this->parsePostgresArray((string) $index['columns']),
            );
        }

        foreach (
            $this->select(
                'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?',
                [$table],
            ) as $index
        ) {
            $name = (string) $index['indexname'];
            if ($this->containsIndex($indexes, $name)) {
                continue;
            }

            $columnsForIndex = [];
            if (preg_match('/\((.+)\)$/', (string) $index['indexdef'], $matches) === 1) {
                $columnsForIndex = array_map(
                    static fn (string $column): string => trim($column, " \t\n\r\0\x0B\""),
                    explode(',', $matches[1]),
                );
            }

            $indexes[] = new IndexSchema(
                $name,
                str_starts_with(strtoupper((string) $index['indexdef']), 'CREATE UNIQUE INDEX')
                    ? IndexSchema::TYPE_UNIQUE
                    : IndexSchema::TYPE_INDEX,
                $columnsForIndex,
            );
        }

        foreach (
            $this->select(
                'SELECT tc.constraint_name, '
                . 'array_agg(kcu.column_name ORDER BY kcu.ordinal_position) AS columns, '
                . 'ccu.table_name AS foreign_table_name, '
                . 'array_agg(ccu.column_name ORDER BY kcu.ordinal_position) AS foreign_columns, '
                . 'rc.update_rule, rc.delete_rule '
                . 'FROM information_schema.table_constraints tc '
                . 'JOIN information_schema.key_column_usage kcu '
                . 'ON kcu.table_schema = tc.table_schema '
                . 'AND kcu.table_name = tc.table_name '
                . 'AND kcu.constraint_name = tc.constraint_name '
                . 'JOIN information_schema.constraint_column_usage ccu '
                . 'ON ccu.constraint_schema = tc.table_schema '
                . 'AND ccu.constraint_name = tc.constraint_name '
                . 'JOIN information_schema.referential_constraints rc '
                . 'ON rc.constraint_schema = tc.table_schema '
                . 'AND rc.constraint_name = tc.constraint_name '
                . 'WHERE tc.table_schema = current_schema() '
                . 'AND tc.table_name = ? '
                . 'AND tc.constraint_type = \'FOREIGN KEY\' '
                . 'GROUP BY tc.constraint_name, ccu.table_name, rc.update_rule, rc.delete_rule',
                [$table],
            ) as $foreignKey
        ) {
            $foreignKeys[] = new ForeignKeySchema(
                name: (string) $foreignKey['constraint_name'],
                columns: $this->parsePostgresArray((string) $foreignKey['columns']),
                referencedTable: (string) $foreignKey['foreign_table_name'],
                referencedColumns: $this->parsePostgresArray((string) $foreignKey['foreign_columns']),
                onDelete: strtoupper((string) $foreignKey['delete_rule']),
                onUpdate: strtoupper((string) $foreignKey['update_rule']),
            );
        }

        return new TableSchema($table, $columns, $indexes, $foreignKeys);
    }

    public function tables(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['table_name'],
            $this->select(
                "SELECT table_name FROM information_schema.tables "
                . "WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' "
                . "ORDER BY table_name ASC",
            ),
        );
    }

    /**
     * @param list<IndexSchema> $indexes
     */
    private function containsIndex(array $indexes, string $name): bool
    {
        foreach ($indexes as $index) {
            if ($index->name() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function parsePostgresArray(string $value): array
    {
        $trimmed = trim($value, '{}');
        if ($trimmed === '') {
            return [];
        }

        return array_map(
            static fn (string $item): string => trim($item, "\" \t\n\r\0\x0B"),
            explode(',', $trimmed),
        );
    }
}
