<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering;

use InvalidArgumentException;
use JsonException;

/**
 * Serializable snapshot of normalized schema metadata for one connection.
 */
final readonly class SchemaSnapshot
{
    /**
     * @param array<string, TableSchema> $tables
     */
    public function __construct(
        private array $tables,
        private ?string $connection = null,
        private ?string $driver = null,
        private ?string $capturedAt = null,
    ) {
    }

    /**
     * Build a snapshot from a list of table definitions.
     *
     * @param list<TableSchema> $tables
     */
    public static function fromTables(
        array $tables,
        ?string $connection = null,
        ?string $driver = null,
        ?string $capturedAt = null,
    ): self {
        $mapped = [];

        foreach ($tables as $table) {
            $mapped[$table->name()] = $table;
        }

        return new self($mapped, $connection, $driver, $capturedAt);
    }

    /**
     * Create a snapshot from JSON.
     *
     * @throws JsonException
     */
    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $tables = [];
        foreach (($decoded['tables'] ?? []) as $table) {
            if (!is_array($table)) {
                throw new InvalidArgumentException('Snapshot table definition must be an array.');
            }

            $tables[] = self::hydrateTable($table);
        }

        return self::fromTables(
            $tables,
            is_string($decoded['connection'] ?? null) ? $decoded['connection'] : null,
            is_string($decoded['driver'] ?? null) ? $decoded['driver'] : null,
            is_string($decoded['captured_at'] ?? null) ? $decoded['captured_at'] : null,
        );
    }

    /**
     * Return a table by name.
     */
    public function table(string $name): TableSchema
    {
        $table = $this->tables[$name] ?? null;

        if (!$table instanceof TableSchema) {
            throw new InvalidArgumentException(sprintf('Snapshot does not contain table "%s".', $name));
        }

        return $table;
    }

    /**
     * Determine whether the snapshot contains a table.
     */
    public function hasTable(string $name): bool
    {
        return isset($this->tables[$name]);
    }

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        $names = array_keys($this->tables);
        sort($names);

        return $names;
    }

    /**
     * @return list<TableSchema>
     */
    public function tables(): array
    {
        $tables = array_values($this->tables);
        usort(
            $tables,
            static fn (TableSchema $left, TableSchema $right): int => $left->name() <=> $right->name(),
        );

        return $tables;
    }

    /**
     * Return the connection alias captured with the snapshot, if available.
     */
    public function connection(): ?string
    {
        return $this->connection;
    }

    /**
     * Return the PDO driver captured with the snapshot, if available.
     */
    public function driver(): ?string
    {
        return $this->driver;
    }

    /**
     * Return the capture timestamp, if available.
     */
    public function capturedAt(): ?string
    {
        return $this->capturedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'driver' => $this->driver,
            'captured_at' => $this->capturedAt,
            'tables' => array_map(
                static fn (TableSchema $table): array => [
                    'name' => $table->name(),
                    'columns' => array_map(
                        static fn (ColumnSchema $column): array => [
                            'name' => $column->name(),
                            'type' => $column->type(),
                            'nullable' => $column->isNullable(),
                            'default' => $column->defaultValue(),
                            'has_default' => $column->hasDefault(),
                            'unsigned' => $column->isUnsigned(),
                            'auto_increment' => $column->isAutoIncrement(),
                            'primary' => $column->isPrimary(),
                            'options' => $column->options(),
                        ],
                        $table->columns(),
                    ),
                    'indexes' => array_map(
                        static fn (IndexSchema $index): array => [
                            'name' => $index->name(),
                            'type' => $index->type(),
                            'columns' => $index->columns(),
                        ],
                        $table->indexes(),
                    ),
                    'foreign_keys' => array_map(
                        static fn (ForeignKeySchema $foreignKey): array => [
                            'name' => $foreignKey->name(),
                            'columns' => $foreignKey->columns(),
                            'referenced_table' => $foreignKey->referencedTable(),
                            'referenced_columns' => $foreignKey->referencedColumns(),
                            'on_delete' => $foreignKey->onDelete(),
                            'on_update' => $foreignKey->onUpdate(),
                        ],
                        $table->foreignKeys(),
                    ),
                ],
                $this->tables(),
            ),
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $table
     */
    private static function hydrateTable(array $table): TableSchema
    {
        $columns = [];
        foreach (($table['columns'] ?? []) as $column) {
            if (!is_array($column)) {
                throw new InvalidArgumentException('Snapshot column definition must be an array.');
            }

            $columns[] = new ColumnSchema(
                name: (string) ($column['name'] ?? ''),
                type: (string) ($column['type'] ?? 'text'),
                nullable: (bool) ($column['nullable'] ?? false),
                defaultValue: $column['default'] ?? null,
                hasDefault: (bool) ($column['has_default'] ?? false),
                unsigned: (bool) ($column['unsigned'] ?? false),
                autoIncrement: (bool) ($column['auto_increment'] ?? false),
                primary: (bool) ($column['primary'] ?? false),
                options: is_array($column['options'] ?? null) ? $column['options'] : [],
            );
        }

        $indexes = [];
        foreach (($table['indexes'] ?? []) as $index) {
            if (!is_array($index)) {
                throw new InvalidArgumentException('Snapshot index definition must be an array.');
            }

            $indexes[] = new IndexSchema(
                name: (string) ($index['name'] ?? ''),
                type: (string) ($index['type'] ?? IndexSchema::TYPE_INDEX),
                columns: is_array($index['columns'] ?? null) ? array_values($index['columns']) : [],
            );
        }

        $foreignKeys = [];
        foreach (($table['foreign_keys'] ?? []) as $foreignKey) {
            if (!is_array($foreignKey)) {
                throw new InvalidArgumentException('Snapshot foreign key definition must be an array.');
            }

            $foreignKeys[] = new ForeignKeySchema(
                name: (string) ($foreignKey['name'] ?? ''),
                columns: is_array($foreignKey['columns'] ?? null) ? array_values($foreignKey['columns']) : [],
                referencedTable: (string) ($foreignKey['referenced_table'] ?? ''),
                referencedColumns: is_array($foreignKey['referenced_columns'] ?? null)
                    ? array_values($foreignKey['referenced_columns'])
                    : [],
                onDelete: is_string($foreignKey['on_delete'] ?? null) ? $foreignKey['on_delete'] : null,
                onUpdate: is_string($foreignKey['on_update'] ?? null) ? $foreignKey['on_update'] : null,
            );
        }

        return new TableSchema(
            name: (string) ($table['name'] ?? ''),
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
        );
    }
}
