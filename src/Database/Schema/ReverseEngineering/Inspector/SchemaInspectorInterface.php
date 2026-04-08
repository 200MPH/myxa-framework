<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering\Inspector;

use Myxa\Database\Schema\ReverseEngineering\TableSchema;

interface SchemaInspectorInterface
{
    /**
     * Inspect a single table.
     */
    public function table(string $table): TableSchema;

    /**
     * @return list<string>
     */
    public function tables(): array;
}
