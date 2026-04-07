<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering;

use InvalidArgumentException;
use LogicException;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Migrations\Migration;
use Myxa\Database\Schema\Blueprint;
use Myxa\Database\Schema\Diff\TableDiff;
use Myxa\Database\Schema\Diff\TableDiffer;
use Myxa\Database\Schema\Schema;
use Myxa\Database\Schema\ReverseEngineering\Inspector\MysqlSchemaInspector;
use Myxa\Database\Schema\ReverseEngineering\Inspector\PostgresSchemaInspector;
use Myxa\Database\Schema\ReverseEngineering\Inspector\SchemaInspectorInterface;
use Myxa\Database\Schema\ReverseEngineering\Inspector\SqliteSchemaInspector;
use PDO;

/**
 * Inspect live database schema and generate framework-native migration source.
 */
final class ReverseEngineer
{
    private ?SchemaInspectorInterface $resolvedInspector = null;

    public function __construct(
        private readonly DatabaseManager $manager,
        private readonly ?string $connection = null,
        ?SchemaInspectorInterface $inspector = null,
    ) {
        $this->resolvedInspector = $inspector;
    }

    /**
     * Inspect a single table into normalized schema metadata.
     */
    public function table(string $table): TableSchema
    {
        return $this->inspector()->table($table);
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return $this->inspector()->tables();
    }

    /**
     * Capture all visible tables for the current connection into a serializable snapshot.
     */
    public function snapshot(): SchemaSnapshot
    {
        $tables = [];

        foreach ($this->tables() as $table) {
            $tables[] = $this->table($table);
        }

        return SchemaSnapshot::fromTables(
            tables: $tables,
            connection: $this->connection,
            driver: strtolower((string) $this->manager->pdo($this->connection)->getAttribute(PDO::ATTR_DRIVER_NAME)),
            capturedAt: gmdate(DATE_ATOM),
        );
    }

    /**
     * Load a schema snapshot from JSON.
     */
    public function snapshotFromJson(string $json): SchemaSnapshot
    {
        return SchemaSnapshot::fromJson($json);
    }

    /**
     * Generate migration class source for a live database table.
     */
    public function migration(string $table, ?string $className = null): string
    {
        $schema = $this->table($table);
        $className ??= $this->defaultMigrationClassName($table);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use Myxa\Database\Migrations\Migration;',
            'use Myxa\Database\Schema\Blueprint;',
            'use Myxa\Database\Schema\Schema;',
            '',
            sprintf('final class %s extends Migration', $className),
            '{',
            '    public function up(Schema $schema): void',
            '    {',
            sprintf("        \$schema->create('%s', function (Blueprint \$table): void {", $schema->name()),
        ];

        foreach ($this->renderColumnLines($schema) as $line) {
            $lines[] = '            ' . $line;
        }

        foreach ($this->renderIndexLines($schema) as $line) {
            $lines[] = '            ' . $line;
        }

        foreach ($this->renderForeignKeyLines($schema) as $line) {
            $lines[] = '            ' . $line;
        }

        $lines[] = '        });';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function down(Schema $schema): void';
        $lines[] = '    {';
        $lines[] = sprintf("        \$schema->drop('%s');", $schema->name());
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Compare two versions of the same table.
     */
    public function diff(TableSchema $from, TableSchema $to): TableDiff
    {
        return (new TableDiffer())->diff($from, $to);
    }

    /**
     * Generate an alter migration class source between two table definitions.
     */
    public function alterMigration(TableSchema $from, TableSchema $to, ?string $className = null): string
    {
        $diff = $this->diff($from, $to);

        if ($diff->hasChangedColumns()) {
            $changedColumns = array_map(
                static fn (\Myxa\Database\Schema\Diff\ColumnChange $change): string => $change->to()->name(),
                $diff->changedColumns(),
            );

            throw new LogicException(sprintf(
                'Alter migration generation does not yet support modified columns: %s.',
                implode(', ', $changedColumns),
            ));
        }

        $className ??= $this->defaultAlterMigrationClassName($diff->table());

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use Myxa\Database\Migrations\Migration;',
            'use Myxa\Database\Schema\Blueprint;',
            'use Myxa\Database\Schema\Schema;',
            '',
            sprintf('final class %s extends Migration', $className),
            '{',
            '    public function up(Schema $schema): void',
            '    {',
            sprintf("        \$schema->table('%s', function (Blueprint \$table): void {", $diff->table()),
        ];

        foreach ($this->renderAlterUpLines($diff) as $line) {
            $lines[] = '            ' . $line;
        }

        $lines[] = '        });';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function down(Schema $schema): void';
        $lines[] = '    {';
        $lines[] = sprintf("        \$schema->table('%s', function (Blueprint \$table): void {", $diff->table());

        foreach ($this->renderAlterDownLines($diff) as $line) {
            $lines[] = '            ' . $line;
        }

        $lines[] = '        });';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate model class source for a live database table.
     */
    public function modelFromTable(string $table, string $className, ?string $namespace = null): string
    {
        return (new ModelGenerator())->generate(
            $this->table($table),
            $className,
            $namespace,
            $this->connection,
        );
    }

    /**
     * Generate model class source by capturing a migration's create blueprint.
     */
    public function modelFromMigration(
        Migration $migration,
        string $className,
        ?string $table = null,
        ?string $namespace = null,
    ): string {
        $blueprints = [];
        $schema = new Schema(
            $this->manager,
            $migration->connectionName() ?? $this->connection,
            null,
            static function (Blueprint $blueprint) use (&$blueprints): void {
                $blueprints[] = $blueprint;
            },
            false,
        );

        $migration->up($schema);

        $blueprint = $this->resolveBlueprintForModelGeneration($migration, $blueprints, $table);

        if (!$blueprint->isCreate()) {
            throw new LogicException(sprintf(
                'Model generation from migration %s requires a create() blueprint.',
                $migration::class,
            ));
        }

        return (new ModelGenerator())->generate(
            (new BlueprintTableSchemaFactory())->fromBlueprint($blueprint),
            $className,
            $namespace,
            $migration->connectionName() ?? $this->connection,
        );
    }

    private function inspector(): SchemaInspectorInterface
    {
        if ($this->resolvedInspector instanceof SchemaInspectorInterface) {
            return $this->resolvedInspector;
        }

        $driver = strtolower((string) $this->manager->pdo($this->connection)->getAttribute(PDO::ATTR_DRIVER_NAME));

        $this->resolvedInspector = match ($driver) {
            'pgsql' => new PostgresSchemaInspector($this->manager, $this->connection),
            'sqlite' => new SqliteSchemaInspector($this->manager, $this->connection),
            default => new MysqlSchemaInspector($this->manager, $this->connection),
        };

        return $this->resolvedInspector;
    }

    private function defaultMigrationClassName(string $table): string
    {
        $segments = array_map(
            static fn (string $segment): string => ucfirst(strtolower($segment)),
            preg_split('/[^a-zA-Z0-9]+/', $table, -1, PREG_SPLIT_NO_EMPTY) ?: [$table],
        );

        return 'Create' . implode('', $segments) . 'Table';
    }

    private function defaultAlterMigrationClassName(string $table): string
    {
        $segments = array_map(
            static fn (string $segment): string => ucfirst(strtolower($segment)),
            preg_split('/[^a-zA-Z0-9]+/', $table, -1, PREG_SPLIT_NO_EMPTY) ?: [$table],
        );

        return 'Alter' . implode('', $segments) . 'Table';
    }

    /**
     * @return list<string>
     */
    private function renderColumnLines(TableSchema $schema): array
    {
        $lines = [];

        foreach ($schema->columns() as $column) {
            if ($this->isConventionalIdColumn($column)) {
                $lines[] = sprintf("\$table->id('%s');", $column->name());

                continue;
            }

            $base = match ($column->type()) {
                'string' => sprintf("\$table->string('%s', %d)", $column->name(), (int) $column->option('length', 255)),
                'decimal' => sprintf(
                    "\$table->decimal('%s', %d, %d)",
                    $column->name(),
                    (int) $column->option('precision', 8),
                    (int) $column->option('scale', 2),
                ),
                default => sprintf("\$table->%s('%s')", $column->type(), $column->name()),
            };

            $chains = [];

            if ($column->isUnsigned()) {
                $chains[] = 'unsigned()';
            }

            if ($column->isAutoIncrement()) {
                $chains[] = 'autoIncrement()';
            }

            if ($column->isPrimary()) {
                $chains[] = 'primary()';
            }

            if ($column->isNullable()) {
                $chains[] = 'nullable()';
            }

            if ($column->hasDefault()) {
                $chains[] = $this->renderDefaultChain($column->defaultValue());
            }

            $lines[] = $base . ($chains === [] ? '' : '->' . implode('->', $chains)) . ';';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderIndexLines(TableSchema $schema): array
    {
        $lines = [];

        foreach ($schema->indexes() as $index) {
            if ($index->type() === IndexSchema::TYPE_PRIMARY) {
                continue;
            }

            $method = $index->type() === IndexSchema::TYPE_UNIQUE ? 'unique' : 'index';
            $columns = count($index->columns()) === 1
                ? "'" . $index->columns()[0] . "'"
                : '[' . implode(', ', array_map(static fn (string $column): string => "'" . $column . "'", $index->columns())) . ']';

            $lines[] = sprintf("\$table->%s(%s, '%s');", $method, $columns, $index->name());
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderForeignKeyLines(TableSchema $schema): array
    {
        $lines = [];

        foreach ($schema->foreignKeys() as $foreignKey) {
            $columns = count($foreignKey->columns()) === 1
                ? "'" . $foreignKey->columns()[0] . "'"
                : '[' . implode(', ', array_map(static fn (string $column): string => "'" . $column . "'", $foreignKey->columns())) . ']';
            $references = count($foreignKey->referencedColumns()) === 1
                ? "'" . $foreignKey->referencedColumns()[0] . "'"
                : '[' . implode(', ', array_map(static fn (string $column): string => "'" . $column . "'", $foreignKey->referencedColumns())) . ']';

            $line = sprintf(
                "\$table->foreign(%s, '%s')->references(%s)->on('%s')",
                $columns,
                $foreignKey->name(),
                $references,
                $foreignKey->referencedTable(),
            );

            if ($foreignKey->onDelete() !== null) {
                $line .= sprintf("->onDelete('%s')", strtolower($foreignKey->onDelete()));
            }

            if ($foreignKey->onUpdate() !== null) {
                $line .= sprintf("->onUpdate('%s')", strtolower($foreignKey->onUpdate()));
            }

            $lines[] = $line . ';';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderAlterUpLines(TableDiff $diff): array
    {
        $lines = [];

        foreach ($diff->droppedForeignKeys() as $foreignKey) {
            $lines[] = sprintf("\$table->dropForeign('%s');", $foreignKey->name());
        }

        foreach ($diff->droppedIndexes() as $index) {
            if ($index->type() === IndexSchema::TYPE_PRIMARY) {
                throw new LogicException('Alter migration generation does not yet support dropping primary keys.');
            }

            $lines[] = sprintf("\$table->dropIndex('%s');", $index->name());
        }

        foreach ($diff->droppedColumns() as $column) {
            $lines[] = sprintf("\$table->dropColumn('%s');", $column->name());
        }

        foreach ($this->renderColumnLines(new TableSchema($diff->table(), $diff->addedColumns(), [], [])) as $line) {
            $lines[] = $line;
        }

        foreach ($this->renderIndexLines(new TableSchema($diff->table(), [], $diff->addedIndexes(), [])) as $line) {
            $lines[] = $line;
        }

        foreach ($this->renderForeignKeyLines(new TableSchema($diff->table(), [], [], $diff->addedForeignKeys())) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderAlterDownLines(TableDiff $diff): array
    {
        $lines = [];

        foreach ($diff->addedForeignKeys() as $foreignKey) {
            $lines[] = sprintf("\$table->dropForeign('%s');", $foreignKey->name());
        }

        foreach ($diff->addedIndexes() as $index) {
            if ($index->type() === IndexSchema::TYPE_PRIMARY) {
                throw new LogicException('Alter migration generation does not yet support dropping primary keys.');
            }

            $lines[] = sprintf("\$table->dropIndex('%s');", $index->name());
        }

        foreach ($diff->addedColumns() as $column) {
            $lines[] = sprintf("\$table->dropColumn('%s');", $column->name());
        }

        foreach ($this->renderColumnLines(new TableSchema($diff->table(), $diff->droppedColumns(), [], [])) as $line) {
            $lines[] = $line;
        }

        foreach ($this->renderIndexLines(new TableSchema($diff->table(), [], $diff->droppedIndexes(), [])) as $line) {
            $lines[] = $line;
        }

        foreach ($this->renderForeignKeyLines(new TableSchema($diff->table(), [], [], $diff->droppedForeignKeys())) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    private function isConventionalIdColumn(ColumnSchema $column): bool
    {
        return $column->name() === 'id'
            && $column->type() === 'bigInteger'
            && $column->isAutoIncrement()
            && $column->isPrimary();
    }

    private function renderDefaultChain(mixed $value): string
    {
        if ($value === 'CURRENT_TIMESTAMP') {
            return 'useCurrent()';
        }

        return 'default(' . $this->exportValue($value) . ')';
    }

    private function exportValue(mixed $value): string
    {
        if ($value === 'CURRENT_TIMESTAMP') {
            return 'new \\Myxa\\Database\\Query\\RawExpression(\'CURRENT_TIMESTAMP\')';
        }

        return var_export($value, true);
    }

    /**
     * @param list<Blueprint> $blueprints
     */
    private function resolveBlueprintForModelGeneration(Migration $migration, array $blueprints, ?string $table): Blueprint
    {
        if ($table !== null) {
            foreach ($blueprints as $blueprint) {
                if ($blueprint->tableName() === $table) {
                    return $blueprint;
                }
            }

            throw new InvalidArgumentException(sprintf(
                'Migration %s does not define a blueprint for table "%s".',
                $migration::class,
                $table,
            ));
        }

        if (count($blueprints) === 1) {
            return $blueprints[0];
        }

        $createBlueprints = array_values(array_filter(
            $blueprints,
            static fn (Blueprint $blueprint): bool => $blueprint->isCreate(),
        ));

        if (count($createBlueprints) === 1) {
            return $createBlueprints[0];
        }

        if ($blueprints === []) {
            throw new LogicException(sprintf(
                'Migration %s did not record any schema blueprints.',
                $migration::class,
            ));
        }

        throw new LogicException(sprintf(
            'Migration %s defines multiple schema blueprints; pass a table name to select one.',
            $migration::class,
        ));
    }
}
