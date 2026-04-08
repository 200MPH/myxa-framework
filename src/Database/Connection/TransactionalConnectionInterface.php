<?php

declare(strict_types=1);

namespace Myxa\Database\Connection;

/**
 * Extension contract for drivers that support transactions.
 */
interface TransactionalConnectionInterface extends ConnectionInterface
{
    /**
     * Begin transaction.
     */
    public function beginTransaction(): bool;

    /**
     * Commit transaction.
     */
    public function commit(): bool;

    /**
     * Roll back transaction.
     */
    public function rollBack(): bool;
}
