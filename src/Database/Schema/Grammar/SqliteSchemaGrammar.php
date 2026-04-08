<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\Grammar;

use LogicException;
use Myxa\Database\Schema\ColumnDefinition;
use Myxa\Database\Schema\ForeignKeyDefinition;
use Myxa\Database\Schema\IndexDefinition;

/**
 * SQLite-flavoured schema grammar for tests and lightweight apps.
 */
final class SqliteSchemaGrammar extends AbstractSchemaGrammar
{
    public function compileDropForeign(string $table, string $name): string
    {
        throw new LogicException(sprintf(
            'SQLite cannot drop foreign keys from table "%s" via ALTER TABLE.',
            $table,
        ));
    }

    protected function wrap(string $identifier): string
    {
        $segments = array_map(
            static fn (string $segment): string => sprintf('"%s"', str_replace('"', '""', trim($segment))),
            explode('.', $identifier),
        );

        return implode('.', $segments);
    }

    protected function compileType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'integer', 'bigInteger', 'boolean' => 'INTEGER',
            'string', 'text', 'json' => $column->type() === 'string'
                ? sprintf('VARCHAR(%d)', (int) $column->option('length', 255))
                : 'TEXT',
            'timestamp', 'dateTime' => 'DATETIME',
            'decimal', 'float' => 'NUMERIC',
            default => throw new LogicException(sprintf(
                'Unsupported SQLite schema column type "%s".',
                $column->type(),
            )),
        };
    }

    protected function supportsUnsigned(): bool
    {
        return false;
    }

    protected function autoIncrementKeyword(): string
    {
        return '';
    }

    protected function requiresSpecialAutoIncrementPrimarySyntax(ColumnDefinition $column): bool
    {
        return $column->isAutoIncrement() && $column->isPrimary();
    }

    protected function compileSpecialAutoIncrementPrimarySyntax(ColumnDefinition $column): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    protected function compileAddPrimaryKey(string $table, IndexDefinition $index): string
    {
        throw new LogicException(sprintf(
            'SQLite cannot add a primary key to table "%s" via ALTER TABLE.',
            $table,
        ));
    }

    protected function compileAddForeignKey(string $table, ForeignKeyDefinition $foreignKey): string
    {
        throw new LogicException(sprintf(
            'SQLite cannot add foreign keys to table "%s" via ALTER TABLE.',
            $table,
        ));
    }
}
