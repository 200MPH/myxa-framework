<?php

declare(strict_types=1);

namespace Myxa\Database\Migrations;

use LogicException;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Schema\Schema;

/**
 * Base class for framework migrations.
 */
abstract class Migration
{
    /**
     * Apply the migration changes.
     */
    abstract public function up(Schema $schema): void;

    /**
     * Roll back the migration changes.
     */
    public function down(Schema $schema): void
    {
        throw new LogicException(sprintf(
            'Migration %s does not implement down().',
            static::class,
        ));
    }

    /**
     * Optional connection alias for this migration.
     */
    public function connectionName(): ?string
    {
        return null;
    }

    /**
     * Indicates whether a future runner should wrap the migration in a transaction.
     */
    public function withinTransaction(): bool
    {
        return true;
    }

    /**
     * Resolve the schema builder for this migration's configured connection.
     */
    final public function schema(DatabaseManager $manager): Schema
    {
        return $manager->schema($this->connectionName());
    }
}
