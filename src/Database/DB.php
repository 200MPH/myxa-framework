<?php

declare(strict_types=1);

namespace Myxa\Database;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Small static DB facade inspired by Laravel's DB helper.
 */
final class DB
{
    public static function connection(string $alias = 'main'): PdoConnection
    {
        return PdoConnection::get($alias);
    }

    public static function pdo(string $alias = 'main'): PDO
    {
        return self::connection($alias)->getPdo();
    }

    public static function query(): QueryBuilder
    {
        return new QueryBuilder();
    }

    public static function raw(string $expression): RawExpression
    {
        return new RawExpression($expression);
    }

    public static function beginTransaction(string $connection = 'main'): bool
    {
        return self::connection($connection)->beginTransaction();
    }

    public static function commit(string $connection = 'main'): bool
    {
        return self::connection($connection)->commit();
    }

    public static function rollBack(string $connection = 'main'): bool
    {
        return self::connection($connection)->rollBack();
    }

    /**
     * Execute callback inside a DB transaction.
     */
    public static function transaction(callable $callback, string $connection = 'main'): mixed
    {
        $dbConnection = self::connection($connection);
        $dbConnection->beginTransaction();

        try {
            $result = $callback();
            $dbConnection->commit();

            return $result;
        } catch (Throwable $throwable) {
            if ($dbConnection->getPdo()->inTransaction()) {
                $dbConnection->rollBack();
            }

            throw $throwable;
        }
    }

    /**
     * Run SELECT query and return all rows as associative arrays.
     *
     * @param array<int|string, scalar|null> $bindings
     * @return list<array<string, mixed>>
     */
    public static function select(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        string $connection = 'main',
    ): array {
        $statement = self::prepare($sql, $bindings, $connection);
        $statement->execute();

        /** @var list<array<string, mixed>> $result */
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $result;
    }

    /**
     * Execute raw SQL statement.
     *
     * @param array<int|string, scalar|null> $bindings
     */
    public static function statement(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        string $connection = 'main',
    ): bool {
        $statement = self::prepare($sql, $bindings, $connection);

        return $statement->execute();
    }

    /**
     * Execute INSERT query and return last inserted ID.
     *
     * @param array<int|string, scalar|null> $bindings
     */
    public static function insert(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        string $connection = 'main',
    ): string|int {
        $statement = self::prepare($sql, $bindings, $connection);
        $statement->execute();

        $lastInsertId = self::pdo($connection)->lastInsertId();
        if ($lastInsertId === false) {
            throw new RuntimeException('Unable to fetch last insert ID.');
        }

        if (ctype_digit($lastInsertId)) {
            $asInt = (int) $lastInsertId;
            if ((string) $asInt === $lastInsertId) {
                return $asInt;
            }
        }

        return $lastInsertId;
    }

    /**
     * Execute UPDATE query and return affected rows.
     *
     * @param array<int|string, scalar|null> $bindings
     */
    public static function update(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        string $connection = 'main',
    ): int {
        return self::executeAffectingStatement($sql, $bindings, $connection);
    }

    /**
     * Execute DELETE query and return affected rows.
     *
     * @param array<int|string, scalar|null> $bindings
     */
    public static function delete(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        string $connection = 'main',
    ): int {
        return self::executeAffectingStatement($sql, $bindings, $connection);
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     */
    private static function executeAffectingStatement(
        string $sql,
        #[SensitiveParameter]
        array $bindings,
        string $connection,
    ): int {
        $statement = self::prepare($sql, $bindings, $connection);
        $statement->execute();

        return $statement->rowCount();
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     */
    private static function prepare(
        string $sql,
        #[SensitiveParameter]
        array $bindings,
        string $connection,
    ): PDOStatement {
        $statement = self::pdo($connection)->prepare($sql);

        foreach ($bindings as $key => $value) {
            $normalizedValue = self::normalizeBindingValue($value);
            $parameter = self::normalizeParameter($key);
            $type = self::resolvePdoType($normalizedValue);

            $statement->bindValue($parameter, $normalizedValue, $type);
        }

        return $statement;
    }

    /**
     * @param int|string $parameter
     * @return int|string
     */
    private static function normalizeParameter(int|string $parameter): int|string
    {
        if (is_int($parameter)) {
            return $parameter + 1;
        }

        if (!str_starts_with($parameter, ':')) {
            return ':' . $parameter;
        }

        return $parameter;
    }

    /**
     * @return scalar|null
     */
    private static function normalizeBindingValue(mixed $value): string|int|float|bool|null
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('Binding value must be a scalar or null.');
    }

    /**
     * @param scalar|null $value
     */
    private static function resolvePdoType(string|int|float|bool|null $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }
}
