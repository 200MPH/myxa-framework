<?php

declare(strict_types=1);

namespace Myxa\Database;

use PDO;
use PDOException;
use RuntimeException;
use SensitiveParameter;

/**
 * PDO connection wrapper with alias-based registry support.
 */
final class PdoConnection implements TransactionalConnectionInterface
{
    /** @var array<string, self> */
    private static array $registry = [];

    private ?PDO $pdo = null;

    /**
     * @param PdoConnectionConfig $config Immutable DB configuration.
     */
    public function __construct(private readonly PdoConnectionConfig $config)
    {
    }

    /**
     * Register an existing connection under an alias.
     *
     * @throws RuntimeException When alias already exists and $replace is false.
     */
    public static function register(string $alias, self $connection, bool $replace = false): self
    {
        if (!$replace && isset(self::$registry[$alias])) {
            throw new RuntimeException(sprintf('Connection alias "%s" is already registered.', $alias));
        }

        self::$registry[$alias] = $connection;

        return $connection;
    }

    /**
     * Create and register a connection from config.
     */
    public static function registerNew(
        string $alias,
        PdoConnectionConfig $config,
        bool $replace = false,
    ): self {
        $connection = new self($config);

        return self::register($alias, $connection, $replace);
    }

    /**
     * Create and register a connection directly from DSN values.
     *
     * @param array<int, mixed> $options PDO options.
     */
    public static function registerFromDsn(
        string $alias,
        string $dsn,
        ?string $username = null,
        #[SensitiveParameter]
        ?string $password = null,
        array $options = [],
        bool $replace = false,
    ): self {
        $config = PdoConnectionConfig::fromDsn($dsn, $username, $password, $options);

        return self::registerNew($alias, $config, $replace);
    }

    /**
     * Get a registered connection by alias.
     *
     * @throws RuntimeException When alias is not registered.
     */
    public static function get(string $alias): self
    {
        $connection = self::$registry[$alias] ?? null;

        if (!$connection instanceof self) {
            throw new RuntimeException(sprintf('Connection alias "%s" is not registered.', $alias));
        }

        return $connection;
    }

    /**
     * Check if an alias is registered.
     */
    public static function has(string $alias): bool
    {
        return isset(self::$registry[$alias]);
    }

    /**
     * Remove alias from registry and optionally disconnect it first.
     */
    public static function unregister(string $alias, bool $disconnect = true): void
    {
        if (!isset(self::$registry[$alias])) {
            return;
        }

        if ($disconnect) {
            self::$registry[$alias]->disconnect();
        }

        unset(self::$registry[$alias]);
    }

    /**
     * Establish (or return existing) PDO connection.
     *
     * @throws RuntimeException When PDO connection cannot be established.
     */
    public function connect(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $options = $this->config->getOptions() + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new PDO(
                $this->config->getDsn(),
                $this->config->getUsername(),
                $this->config->getPassword(),
                $options,
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to establish database connection.', previous: $exception);
        }

        return $this->pdo;
    }

    /**
     * Shortcut for connect().
     */
    public function getPdo(): PDO
    {
        return $this->connect();
    }

    /**
     * Close PDO connection.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * True when PDO handle is already initialized.
     */
    public function isConnected(): bool
    {
        return $this->pdo instanceof PDO;
    }

    /**
     * Begin transaction.
     */
    public function beginTransaction(): bool
    {
        return $this->connect()->beginTransaction();
    }

    /**
     * Commit transaction.
     */
    public function commit(): bool
    {
        return $this->connect()->commit();
    }

    /**
     * Roll back transaction.
     */
    public function rollBack(): bool
    {
        return $this->connect()->rollBack();
    }

    /**
     * Return immutable connection config.
     */
    public function getPdoConnectionConfig(): PdoConnectionConfig
    {
        return $this->config;
    }

    /**
     * Backward-compatible alias for getPdoConnectionConfig().
     */
    public function getConfig(): PdoConnectionConfig
    {
        return $this->getPdoConnectionConfig();
    }
}
