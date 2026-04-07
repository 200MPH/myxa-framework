<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\Grammar;

use LogicException;
use Myxa\Database\Schema\ColumnDefinition;

/**
 * SQL Server-flavoured schema grammar.
 */
final class SqlServerSchemaGrammar extends AbstractSchemaGrammar
{
    public function compileDrop(string $table, bool $ifExists = false): string
    {
        if (!$ifExists) {
            return parent::compileDrop($table, false);
        }

        return sprintf(
            'IF OBJECT_ID(N\'%s\', N\'U\') IS NOT NULL DROP TABLE %s',
            str_replace("'", "''", $table),
            $this->wrap($table),
        );
    }

    public function compileRename(string $from, string $to): string
    {
        return sprintf(
            'EXEC sp_rename N\'%s\', N\'%s\'',
            str_replace("'", "''", $from),
            str_replace("'", "''", $to),
        );
    }

    public function compileDropIndex(string $table, string $name): string
    {
        return sprintf(
            'DROP INDEX %s ON %s',
            $this->wrap($name),
            $this->wrap($table),
        );
    }

    public function compileDropForeign(string $table, string $name): string
    {
        return sprintf(
            'ALTER TABLE %s DROP CONSTRAINT %s',
            $this->wrap($table),
            $this->wrap($name),
        );
    }

    protected function wrap(string $identifier): string
    {
        $segments = array_map(
            static fn (string $segment): string => sprintf('[%s]', str_replace(']', ']]', trim($segment))),
            explode('.', $identifier),
        );

        return implode('.', $segments);
    }

    protected function compileType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'string' => sprintf('NVARCHAR(%d)', (int) $column->option('length', 255)),
            'text', 'json' => 'NVARCHAR(MAX)',
            'boolean' => 'BIT',
            'timestamp', 'dateTime' => 'DATETIME2',
            'decimal' => sprintf(
                'DECIMAL(%d, %d)',
                (int) $column->option('precision', 8),
                (int) $column->option('scale', 2),
            ),
            'float' => 'FLOAT',
            default => throw new LogicException(sprintf(
                'Unsupported SQL Server schema column type "%s".',
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
        return 'IDENTITY(1,1)';
    }

    protected function compileRenameColumn(string $table, string $from, string $to): string
    {
        return sprintf(
            'EXEC sp_rename N\'%s.%s\', N\'%s\', N\'COLUMN\'',
            str_replace("'", "''", $table),
            str_replace("'", "''", $from),
            str_replace("'", "''", $to),
        );
    }
}
