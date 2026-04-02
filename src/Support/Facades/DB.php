<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use Myxa\Database\DatabaseException;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Query\QueryBuilder;
use Myxa\Database\Query\RawExpression;
use PDO;
use SensitiveParameter;

/**
 * Small static DB facade inspired by Laravel's DB helper.
 */
final class DB
{
    private static ?DatabaseManager $manager = null;

    public static function setManager(DatabaseManager $manager): void
    {
        self::$manager = $manager;
    }

    public static function clearManager(): void
    {
        self::$manager = null;
    }

    public static function getManager(): DatabaseManager
    {
        return self::$manager ??= new DatabaseManager();
    }

    public static function connection(?string $alias = null): PdoConnection
    {
        return self::getManager()->connection($alias);
    }

    public static function pdo(?string $alias = null): PDO
    {
        return self::getManager()->pdo($alias);
    }

    public static function query(): QueryBuilder
    {
        return self::getManager()->query();
    }

    public static function raw(string $expression): RawExpression
    {
        return self::getManager()->raw($expression);
    }

    /**
     * Render SQL with bindings inlined for debugging.
     * Unsafe for logs, exceptions, or user-facing output because real binding values are included.
     *
     * @param array<int|string, scalar|null> $bindings
     */
    public static function toRawSql(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): string {
        return self::getManager()->toRawSql($sql, $bindings, $connection);
    }

    public static function beginTransaction(?string $connection = null): bool
    {
        return self::getManager()->beginTransaction($connection);
    }

    public static function commit(?string $connection = null): bool
    {
        return self::getManager()->commit($connection);
    }

    public static function rollBack(?string $connection = null): bool
    {
        return self::getManager()->rollBack($connection);
    }

    /**
     * Execute callback inside a DB transaction.
     */
    public static function transaction(callable $callback, ?string $connection = null): mixed
    {
        return self::getManager()->transaction($callback, $connection);
    }

    /**
     * Run SELECT query and return all rows as associative arrays.
     *
     * @param array<int|string, scalar|null> $bindings
     * @return list<array<string, mixed>>
     * @throws DatabaseException
     */
    public static function select(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): array {
        return self::getManager()->select($sql, $bindings, $connection);
    }

    /**
     * Execute raw SQL statement.
     *
     * @param array<int|string, scalar|null> $bindings
     * @throws DatabaseException
     */
    public static function statement(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): bool {
        return self::getManager()->statement($sql, $bindings, $connection);
    }

    /**
     * Execute INSERT query and return last inserted ID.
     *
     * @param array<int|string, scalar|null> $bindings
     * @throws DatabaseException
     */
    public static function insert(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): string|int {
        return self::getManager()->insert($sql, $bindings, $connection);
    }

    /**
     * Execute UPDATE query and return affected rows.
     *
     * @param array<int|string, scalar|null> $bindings
     * @throws DatabaseException
     */
    public static function update(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): int {
        return self::getManager()->update($sql, $bindings, $connection);
    }

    /**
     * Execute DELETE query and return affected rows.
     *
     * @param array<int|string, scalar|null> $bindings
     * @throws DatabaseException
     */
    public static function delete(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): int {
        return self::getManager()->delete($sql, $bindings, $connection);
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        return self::getManager()->{$name}(...$arguments);
    }
}
