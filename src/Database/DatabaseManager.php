<?php

declare(strict_types=1);

namespace Myxa\Database;

use Closure;
use Generator;
use InvalidArgumentException;
use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Connection\PdoConnectionConfig;
use Myxa\Database\Exceptions\DatabaseException;
use Myxa\Database\Query\Grammar\MysqlQueryGrammar;
use Myxa\Database\Query\Grammar\PostgresQueryGrammar;
use Myxa\Database\Query\Grammar\QueryGrammarInterface;
use Myxa\Database\Query\Grammar\SqlServerQueryGrammar;
use Myxa\Database\Query\Grammar\SqliteQueryGrammar;
use Myxa\Database\Query\QueryBuilder;
use Myxa\Database\Query\RawExpression;
use Myxa\Database\Query\SqlInterpolator;
use Myxa\Database\Schema\Schema;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionFunction;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Stateful database service responsible for managed connections and query execution.
 */
final class DatabaseManager
{
    private const string DEFAULT_CONNECTION = 'main';

    /** @var array<string, PdoConnection> */
    private array $connections = [];

    /** @var array<string, Closure(self): PdoConnection> */
    private array $connectionFactories = [];

    public function __construct(private string $defaultConnection = self::DEFAULT_CONNECTION)
    {
        $this->defaultConnection = $this->normalizeConnectionName($this->defaultConnection);
    }

    /**
     * @param PdoConnection|PdoConnectionConfig|callable(self): PdoConnection|callable(): PdoConnection $connection
     */
    public function addConnection(
        string $alias,
        PdoConnection|PdoConnectionConfig|callable $connection,
        bool $replace = false,
    ): self {
        $alias = $this->normalizeConnectionName($alias);

        if (!$replace && ($this->hasManagedConnection($alias) || PdoConnection::has($alias))) {
            throw new RuntimeException(sprintf('Connection alias "%s" is already registered.', $alias));
        }

        unset($this->connections[$alias], $this->connectionFactories[$alias]);

        if ($connection instanceof PdoConnection) {
            $this->connections[$alias] = $connection;

            return $this;
        }

        if ($connection instanceof PdoConnectionConfig) {
            $this->connectionFactories[$alias] = static fn (
                self $manager,
            ): PdoConnection => new PdoConnection($connection);

            return $this;
        }

        $this->connectionFactories[$alias] = $this->normalizeConnectionFactory($alias, $connection);

        return $this;
    }

    public function hasConnection(string $alias): bool
    {
        $alias = $this->normalizeConnectionName($alias);

        return $this->hasManagedConnection($alias) || PdoConnection::has($alias);
    }

    public function connection(?string $alias = null): PdoConnection
    {
        $resolvedAlias = $this->resolveConnectionName($alias);

        if (isset($this->connections[$resolvedAlias])) {
            return $this->connections[$resolvedAlias];
        }

        if (isset($this->connectionFactories[$resolvedAlias])) {
            $this->connections[$resolvedAlias] = $this->resolveConnectionFactory(
                $resolvedAlias,
                $this->connectionFactories[$resolvedAlias],
            );

            return $this->connections[$resolvedAlias];
        }

        if (PdoConnection::has($resolvedAlias)) {
            return PdoConnection::get($resolvedAlias);
        }

        throw new RuntimeException(sprintf('Connection alias "%s" is not registered.', $resolvedAlias));
    }

    public function pdo(?string $alias = null): PDO
    {
        return $this->connection($alias)->getPdo();
    }

    public function query(?string $connection = null): QueryBuilder
    {
        $resolvedConnection = $connection !== null
            ? $this->normalizeConnectionName($connection)
            : $this->getDefaultConnection();

        if (!$this->hasConnection($resolvedConnection)) {
            return new QueryBuilder();
        }

        return new QueryBuilder($this->queryGrammar($resolvedConnection));
    }

    /**
     * Start a fluent schema builder for the given connection.
     */
    public function schema(?string $connection = null): Schema
    {
        return new Schema($this, $connection);
    }

    public function raw(string $expression): RawExpression
    {
        return new RawExpression($expression);
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     */
    public function toRawSql(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): string {
        return SqlInterpolator::interpolate($sql, $bindings, $this->pdo($connection));
    }

    public function beginTransaction(?string $connection = null): bool
    {
        return $this->connection($connection)->beginTransaction();
    }

    public function commit(?string $connection = null): bool
    {
        return $this->connection($connection)->commit();
    }

    public function rollBack(?string $connection = null): bool
    {
        return $this->connection($connection)->rollBack();
    }

    public function getDefaultConnection(): string
    {
        return $this->defaultConnection;
    }

    public function setDefaultConnection(string $alias): self
    {
        $this->defaultConnection = $this->normalizeConnectionName($alias);

        return $this;
    }

    /**
     * Execute callback inside a DB transaction.
     */
    public function transaction(callable $callback, ?string $connection = null): mixed
    {
        $dbConnection = $this->connection($connection);
        $dbConnection->beginTransaction();

        try {
            $result = $callback();

            if ($dbConnection->getPdo()->inTransaction()) {
                $dbConnection->commit();
            }

            return $result;
        } catch (Throwable $throwable) {
            if ($dbConnection->getPdo()->inTransaction()) {
                $dbConnection->rollBack();
            }

            throw $throwable;
        }
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     * @return list<array<string, mixed>>
     * @throws DatabaseException
     */
    public function select(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): array {
        $resolvedConnection = $this->resolveConnectionName($connection);

        return $this->runQuery(
            $sql,
            $resolvedConnection,
            function () use ($sql, $bindings, $resolvedConnection): array {
                $statement = $this->prepare($sql, $bindings, $resolvedConnection);
                $statement->execute();

                /** @var list<array<string, mixed>> $result */
                $result = $statement->fetchAll(PDO::FETCH_ASSOC);

                return $result;
            },
        );
    }

    /**
     * Stream selected rows one at a time.
     *
     * @param array<int|string, scalar|null> $bindings
     * @return Generator<int, array<string, mixed>, void, void>
     * @throws DatabaseException
     */
    public function cursor(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): Generator {
        $resolvedConnection = $this->resolveConnectionName($connection);
        $statement = null;

        try {
            $statement = $this->prepare($sql, $bindings, $resolvedConnection);
            $statement->execute();

            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                /** @var array<string, mixed> $row */
                yield $row;
            }
        } catch (PDOException $exception) {
            throw DatabaseException::fromPdoException($exception, $sql, $resolvedConnection);
        } finally {
            $statement?->closeCursor();
        }
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     * @throws DatabaseException
     */
    public function statement(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): bool {
        $resolvedConnection = $this->resolveConnectionName($connection);

        return $this->runQuery(
            $sql,
            $resolvedConnection,
            function () use ($sql, $bindings, $resolvedConnection): bool {
                $statement = $this->prepare($sql, $bindings, $resolvedConnection);

                return $statement->execute();
            },
        );
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     * @throws DatabaseException
     */
    public function insert(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): string|int {
        $resolvedConnection = $this->resolveConnectionName($connection);

        return $this->runQuery(
            $sql,
            $resolvedConnection,
            function () use ($sql, $bindings, $resolvedConnection): string|int {
                $statement = $this->prepare($sql, $bindings, $resolvedConnection);
                $statement->execute();

                $lastInsertId = $this->pdo($resolvedConnection)->lastInsertId();
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
            },
        );
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     * @throws DatabaseException
     */
    public function update(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): int {
        return $this->executeAffectingStatement($sql, $bindings, $connection);
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     * @throws DatabaseException
     */
    public function delete(
        string $sql,
        #[SensitiveParameter]
        array $bindings = [],
        ?string $connection = null,
    ): int {
        return $this->executeAffectingStatement($sql, $bindings, $connection);
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     * @throws DatabaseException
     */
    private function executeAffectingStatement(
        string $sql,
        #[SensitiveParameter]
        array $bindings,
        ?string $connection,
    ): int {
        $resolvedConnection = $this->resolveConnectionName($connection);

        return $this->runQuery($sql, $resolvedConnection, function () use ($sql, $bindings, $resolvedConnection): int {
            $statement = $this->prepare($sql, $bindings, $resolvedConnection);
            $statement->execute();

            return $statement->rowCount();
        });
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     */
    private function prepare(
        string $sql,
        #[SensitiveParameter]
        array $bindings,
        string $connection,
    ): PDOStatement {
        $statement = $this->pdo($connection)->prepare($sql);

        foreach ($bindings as $key => $value) {
            $normalizedValue = $this->normalizeBindingValue($value);
            $parameter = $this->normalizeParameter($key);
            $type = $this->resolvePdoType($normalizedValue);

            $statement->bindValue($parameter, $normalizedValue, $type);
        }

        return $statement;
    }

    /**
     * @template TResult
     * @param callable(): TResult $callback
     * @return TResult
     * @throws DatabaseException
     */
    private function runQuery(string $sql, string $connection, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (PDOException $exception) {
            throw DatabaseException::fromPdoException($exception, $sql, $connection);
        }
    }

    /**
     * @param int|string $parameter
     * @return int|string
     */
    private function normalizeParameter(int|string $parameter): int|string
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
    private function normalizeBindingValue(mixed $value): string|int|float|bool|null
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('Binding value must be a scalar or null.');
    }

    /**
     * @param scalar|null $value
     */
    private function resolvePdoType(string|int|float|bool|null $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    private function hasManagedConnection(string $alias): bool
    {
        return isset($this->connections[$alias]) || isset($this->connectionFactories[$alias]);
    }

    private function resolveConnectionName(?string $alias): string
    {
        if ($alias === null) {
            return $this->defaultConnection;
        }

        return $this->normalizeConnectionName($alias);
    }

    private function normalizeConnectionName(string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new InvalidArgumentException('Connection alias cannot be empty.');
        }

        return $alias;
    }

    /**
     * @param Closure(self): PdoConnection $factory
     */
    private function resolveConnectionFactory(string $alias, Closure $factory): PdoConnection
    {
        $connection = $factory($this);

        if (!$connection instanceof PdoConnection) {
            throw new RuntimeException(sprintf(
                'Connection factory for alias "%s" must return %s.',
                $alias,
                PdoConnection::class,
            ));
        }

        return $connection;
    }

    /**
     * @param callable(self): PdoConnection|callable(): PdoConnection $factory
     * @return Closure(self): PdoConnection
     */
    private function normalizeConnectionFactory(string $alias, callable $factory): Closure
    {
        $factory = Closure::fromCallable($factory);
        $reflection = new ReflectionFunction($factory);
        $parameterCount = $reflection->getNumberOfParameters();

        if ($parameterCount > 1) {
            throw new InvalidArgumentException(sprintf(
                'Connection factory for alias "%s" must accept zero or one parameter.',
                $alias,
            ));
        }

        if ($parameterCount === 0) {
            return static fn (self $manager): PdoConnection => $factory();
        }

        return static fn (self $manager): PdoConnection => $factory($manager);
    }

    private function queryGrammar(?string $connection = null): QueryGrammarInterface
    {
        $driver = strtolower((string) $this->pdo($connection)->getAttribute(PDO::ATTR_DRIVER_NAME));

        return match ($driver) {
            'pgsql' => new PostgresQueryGrammar(),
            'sqlite' => new SqliteQueryGrammar(),
            'sqlsrv' => new SqlServerQueryGrammar(),
            default => new MysqlQueryGrammar(),
        };
    }
}
