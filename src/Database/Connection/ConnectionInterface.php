<?php

declare(strict_types=1);

namespace Myxa\Database\Connection;

/**
 * Generic connection lifecycle contract for DB drivers.
 */
interface ConnectionInterface
{
    /**
     * Establish and return an underlying client/connection object.
     */
    public function connect(): object;

    /**
     * Close the active connection.
     */
    public function disconnect(): void;

    /**
     * Check whether a live connection object is already initialized.
     */
    public function isConnected(): bool;
}
