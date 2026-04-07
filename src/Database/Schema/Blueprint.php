<?php

declare(strict_types=1);

namespace Myxa\Database\Schema;

use InvalidArgumentException;

/**
 * In-memory definition of a table create/alter operation.
 */
final class Blueprint
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<IndexDefinition> */
    private array $indexes = [];

    /** @var list<ForeignKeyDefinition> */
    private array $foreignKeys = [];

    /** @var list<string> */
    private array $droppedColumns = [];

    /** @var list<array{from: string, to: string}> */
    private array $renamedColumns = [];

    /** @var list<string> */
    private array $rawStatements = [];

    /** @var list<string> */
    private array $droppedIndexes = [];

    /** @var list<string> */
    private array $droppedForeignKeys = [];

    private function __construct(
        private readonly string $table,
        private readonly bool $create = false,
    ) {
        if (trim($this->table) === '') {
            throw new InvalidArgumentException('Table name cannot be empty.');
        }
    }

    public static function create(string $table): self
    {
        return new self($table, true);
    }

    public static function table(string $table): self
    {
        return new self($table);
    }

    /**
     * Return the table name targeted by this blueprint.
     */
    public function tableName(): string
    {
        return $this->table;
    }

    /**
     * Determine whether this blueprint represents a CREATE TABLE operation.
     */
    public function isCreate(): bool
    {
        return $this->create;
    }

    /**
     * Add an auto-incrementing unsigned big integer primary key column.
     */
    public function id(string $column = 'id'): ColumnDefinition
    {
        return $this->bigInteger($column)->unsigned()->autoIncrement()->primary();
    }

    /**
     * Alias for id().
     */
    public function increments(string $column = 'id'): ColumnDefinition
    {
        return $this->id($column);
    }

    /**
     * Add an integer column.
     */
    public function integer(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'integer');
    }

    /**
     * Add a big integer column.
     */
    public function bigInteger(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'bigInteger');
    }

    /**
     * Add a variable-length string column.
     */
    public function string(string $column, int $length = 255): ColumnDefinition
    {
        if ($length < 1) {
            throw new InvalidArgumentException('String column length must be greater than 0.');
        }

        return $this->addColumn($column, 'string', ['length' => $length]);
    }

    /**
     * Add a text column.
     */
    public function text(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'text');
    }

    /**
     * Add a boolean column.
     */
    public function boolean(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'boolean');
    }

    /**
     * Add a timestamp column.
     */
    public function timestamp(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'timestamp');
    }

    /**
     * Add a datetime column.
     */
    public function dateTime(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'dateTime');
    }

    /**
     * Add a JSON column.
     */
    public function json(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'json');
    }

    /**
     * Add a decimal column with the given precision and scale.
     */
    public function decimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        if ($precision < 1) {
            throw new InvalidArgumentException('Decimal precision must be greater than 0.');
        }

        if ($scale < 0) {
            throw new InvalidArgumentException('Decimal scale cannot be negative.');
        }

        if ($scale > $precision) {
            throw new InvalidArgumentException('Decimal scale cannot be greater than precision.');
        }

        return $this->addColumn($column, 'decimal', [
            'precision' => $precision,
            'scale' => $scale,
        ]);
    }

    /**
     * Add a floating-point column.
     */
    public function float(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'float');
    }

    /**
     * Add nullable created_at and updated_at timestamp columns.
     */
    public function timestamps(): self
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();

        return $this;
    }

    /**
     * Add a primary key definition.
     */
    public function primary(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = new IndexDefinition(
            type: IndexDefinition::TYPE_PRIMARY,
            columns: $this->normalizeColumns($columns),
            name: $name ?? $this->createIndexName($columns, IndexDefinition::TYPE_PRIMARY),
        );

        return $this;
    }

    /**
     * Add a unique index definition.
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = new IndexDefinition(
            type: IndexDefinition::TYPE_UNIQUE,
            columns: $this->normalizeColumns($columns),
            name: $name ?? $this->createIndexName($columns, IndexDefinition::TYPE_UNIQUE),
        );

        return $this;
    }

    /**
     * Add a non-unique index definition.
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = new IndexDefinition(
            type: IndexDefinition::TYPE_INDEX,
            columns: $this->normalizeColumns($columns),
            name: $name ?? $this->createIndexName($columns, IndexDefinition::TYPE_INDEX),
        );

        return $this;
    }

    /**
     * Start a foreign key definition for one or more columns.
     */
    public function foreign(string|array $columns, ?string $name = null): ForeignKeyDefinition
    {
        $definition = new ForeignKeyDefinition(
            columns: $this->normalizeColumns($columns),
            name: $name ?? $this->createIndexName($columns, 'foreign'),
        );

        $this->foreignKeys[] = $definition;

        return $definition;
    }

    /**
     * Mark one or more columns for removal.
     */
    public function dropColumn(string|array $columns): self
    {
        foreach ($this->normalizeColumns($columns) as $column) {
            $this->droppedColumns[] = $column;
        }

        return $this;
    }

    /**
     * Mark a column for renaming.
     */
    public function renameColumn(string $from, string $to): self
    {
        if (trim($from) === '' || trim($to) === '') {
            throw new InvalidArgumentException('Rename column names cannot be empty.');
        }

        $this->renamedColumns[] = ['from' => $from, 'to' => $to];

        return $this;
    }

    /**
     * Mark an index for removal by name.
     */
    public function dropIndex(string $name): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Index name cannot be empty.');
        }

        $this->droppedIndexes[] = $name;

        return $this;
    }

    /**
     * Mark a foreign key constraint for removal by name.
     */
    public function dropForeign(string $name): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Foreign key name cannot be empty.');
        }

        $this->droppedForeignKeys[] = $name;

        return $this;
    }

    /**
     * Append a raw SQL statement to the generated schema operation.
     */
    public function raw(string $statement): self
    {
        if (trim($statement) === '') {
            throw new InvalidArgumentException('Raw schema statement cannot be empty.');
        }

        $this->rawStatements[] = $statement;

        return $this;
    }

    /**
     * @return list<ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<IndexDefinition>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @return list<ForeignKeyDefinition>
     */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * @return list<string>
     */
    public function droppedColumns(): array
    {
        return $this->droppedColumns;
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    public function renamedColumns(): array
    {
        return $this->renamedColumns;
    }

    /**
     * @return list<string>
     */
    public function rawStatements(): array
    {
        return $this->rawStatements;
    }

    /**
     * @return list<string>
     */
    public function droppedIndexes(): array
    {
        return $this->droppedIndexes;
    }

    /**
     * @return list<string>
     */
    public function droppedForeignKeys(): array
    {
        return $this->droppedForeignKeys;
    }

    /**
     * @param array<string, int> $options
     */
    private function addColumn(string $column, string $type, array $options = []): ColumnDefinition
    {
        $definition = new ColumnDefinition($this, $column, $type, $options);
        $this->columns[] = $definition;

        return $definition;
    }

    /**
     * @return list<string>
     */
    private function normalizeColumns(string|array $columns): array
    {
        $normalized = is_array($columns) ? array_values($columns) : [$columns];

        if ($normalized === []) {
            throw new InvalidArgumentException('Index and key columns cannot be empty.');
        }

        foreach ($normalized as $column) {
            if (!is_string($column) || trim($column) === '') {
                throw new InvalidArgumentException('Column names must be non-empty strings.');
            }
        }

        return $normalized;
    }

    private function createIndexName(string|array $columns, string $suffix): string
    {
        $parts = array_merge([$this->table], $this->normalizeColumns($columns), [$suffix]);
        $name = strtolower(implode('_', $parts));

        return trim((string) preg_replace('/[^a-z0-9_]+/', '_', $name), '_');
    }
}
