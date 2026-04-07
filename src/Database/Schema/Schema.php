<?php

declare(strict_types=1);

namespace Myxa\Database\Schema;

use Closure;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Migrations\Migration;
use Myxa\Database\Schema\Grammar\MysqlSchemaGrammar;
use Myxa\Database\Schema\Grammar\PostgresSchemaGrammar;
use Myxa\Database\Schema\Grammar\SchemaGrammarInterface;
use Myxa\Database\Schema\Grammar\SqliteSchemaGrammar;
use Myxa\Database\Schema\ReverseEngineering\ReverseEngineer;
use PDO;

/**
 * Fluent schema builder that compiles table blueprints into executable SQL.
 */
final class Schema
{
    private ?SchemaGrammarInterface $resolvedGrammar = null;

    public function __construct(
        private readonly DatabaseManager $manager,
        private readonly ?string $connection = null,
        ?SchemaGrammarInterface $grammar = null,
        ?Closure $blueprintObserver = null,
        private readonly bool $executeStatements = true,
    ) {
        $this->resolvedGrammar = $grammar;
        $this->blueprintObserver = $blueprintObserver;
    }

    private readonly ?Closure $blueprintObserver;

    /**
     * Clone the schema builder for another connection alias.
     */
    public function connection(?string $connection): self
    {
        return new self($this->manager, $connection);
    }

    /**
     * Return the active connection alias, if one was set.
     */
    public function connectionName(): ?string
    {
        return $this->connection;
    }

    /**
     * Create a new table from the provided blueprint callback.
     */
    public function create(string $table, callable $callback): void
    {
        $blueprint = Blueprint::create($table);
        $callback($blueprint);

        $this->build($blueprint);
    }

    /**
     * Alter an existing table using the provided blueprint callback.
     */
    public function table(string $table, callable $callback): void
    {
        $blueprint = Blueprint::table($table);
        $callback($blueprint);

        $this->build($blueprint);
    }

    /**
     * Compile and execute the given blueprint.
     */
    public function build(Blueprint $blueprint): void
    {
        if (is_callable($this->blueprintObserver)) {
            ($this->blueprintObserver)($blueprint);
        }

        if (!$this->executeStatements) {
            return;
        }

        foreach ($this->toSql($blueprint) as $statement) {
            $this->manager->statement($statement, [], $this->connection);
        }
    }

    /**
     * @return list<string>
     */
    public function toSql(Blueprint $blueprint): array
    {
        return $blueprint->isCreate()
            ? $this->grammar()->compileCreate($blueprint)
            : $this->grammar()->compileAlter($blueprint);
    }

    /**
     * Drop a table.
     */
    public function drop(string $table): void
    {
        $this->manager->statement($this->grammar()->compileDrop($table), [], $this->connection);
    }

    /**
     * Drop a table if it exists.
     */
    public function dropIfExists(string $table): void
    {
        $this->manager->statement($this->grammar()->compileDrop($table, true), [], $this->connection);
    }

    /**
     * Rename a table.
     */
    public function rename(string $from, string $to): void
    {
        $this->manager->statement($this->grammar()->compileRename($from, $to), [], $this->connection);
    }

    /**
     * Execute a literal schema SQL statement without bindings.
     */
    public function raw(string $statement): bool
    {
        return $this->manager->statement($statement, [], $this->connection);
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     */
    public function statement(string $statement, array $bindings = []): bool
    {
        return $this->manager->statement($statement, $bindings, $this->connection);
    }

    /**
     * Start reverse engineering for the active connection.
     */
    public function reverseEngineer(): ReverseEngineer
    {
        return new ReverseEngineer($this->manager, $this->connection);
    }

    /**
     * Generate model class source from a live database table.
     */
    public function modelFromTable(string $table, string $className, ?string $namespace = null): string
    {
        return $this->reverseEngineer()->modelFromTable($table, $className, $namespace);
    }

    /**
     * Generate model class source from a migration's captured schema blueprint.
     */
    public function modelFromMigration(
        Migration $migration,
        string $className,
        ?string $table = null,
        ?string $namespace = null,
    ): string {
        return $this->reverseEngineer()->modelFromMigration($migration, $className, $table, $namespace);
    }

    private function grammar(): SchemaGrammarInterface
    {
        if ($this->resolvedGrammar instanceof SchemaGrammarInterface) {
            return $this->resolvedGrammar;
        }

        $driver = strtolower((string) $this->manager->pdo($this->connection)->getAttribute(PDO::ATTR_DRIVER_NAME));

        $this->resolvedGrammar = match ($driver) {
            'pgsql' => new PostgresSchemaGrammar(),
            'sqlite' => new SqliteSchemaGrammar(),
            default => new MysqlSchemaGrammar(),
        };

        return $this->resolvedGrammar;
    }
}
