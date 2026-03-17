<?php

declare(strict_types=1);

namespace Myxa\Database;

use PDOException;
use RuntimeException;

/**
 * Wraps low-level PDO failures with query context.
 */
final class DatabaseException extends RuntimeException
{
    /**
     * @param string $sql SQL template with placeholders. Do not pass interpolated SQL.
     */
    private function __construct(
        string $message,
        private readonly string $sql,
        private readonly string $connection,
        private readonly int|string $driverCode = 0,
        ?PDOException $previous = null,
    ) {
        parent::__construct($message, is_int($driverCode) ? $driverCode : 0, $previous);
    }

    /**
     * Create an exception from a PDO failure while keeping SQL separate from the message.
     *
     * @param string $sql SQL template with placeholders. Do not pass interpolated SQL.
     */
    public static function fromPdoException(
        PDOException $exception,
        string $sql,
        string $connection,
    ): self {
        return new self(
            sprintf('Database query failed on connection "%s".', $connection),
            $sql,
            $connection,
            $exception->getCode(),
            $exception,
        );
    }

    /**
     * SQL template with placeholders.
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    public function getConnection(): string
    {
        return $this->connection;
    }

    public function getDriverCode(): int|string
    {
        return $this->driverCode;
    }
}
