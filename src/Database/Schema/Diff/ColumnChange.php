<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\Diff;

use Myxa\Database\Schema\ReverseEngineering\ColumnSchema;

/**
 * Describes a changed column between two table definitions.
 */
final readonly class ColumnChange
{
    public function __construct(
        private ColumnSchema $from,
        private ColumnSchema $to,
    ) {
    }

    /**
     * Return the original column definition.
     */
    public function from(): ColumnSchema
    {
        return $this->from;
    }

    /**
     * Return the target column definition.
     */
    public function to(): ColumnSchema
    {
        return $this->to;
    }
}
