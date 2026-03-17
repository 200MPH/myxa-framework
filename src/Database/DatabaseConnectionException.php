<?php

declare(strict_types=1);

namespace Myxa\Database;

use PDOException;
use RuntimeException;

/**
 * Wraps low-level PDO connection failures with DSN context.
 */
final class DatabaseConnectionException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $dsn,
        private readonly int|string $driverCode = 0,
        ?PDOException $previous = null,
    ) {
        parent::__construct($message, is_int($driverCode) ? $driverCode : 0, $previous);
    }

    public static function fromPdoException(PDOException $exception, string $dsn): self
    {
        return new self(
            'Failed to establish database connection.',
            $dsn,
            $exception->getCode(),
            $exception,
        );
    }

    public function getDsn(): string
    {
        return $this->dsn;
    }

    public function getDriverCode(): int|string
    {
        return $this->driverCode;
    }
}
