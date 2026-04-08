<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\Grammar;

use LogicException;
use Myxa\Database\Schema\ColumnDefinition;

/**
 * MySQL-flavoured schema grammar.
 */
final class MysqlSchemaGrammar extends AbstractSchemaGrammar
{
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
            'ALTER TABLE %s DROP FOREIGN KEY %s',
            $this->wrap($table),
            $this->wrap($name),
        );
    }

    protected function wrap(string $identifier): string
    {
        $segments = array_map(
            static fn (string $segment): string => sprintf('`%s`', str_replace('`', '``', trim($segment))),
            explode('.', $identifier),
        );

        return implode('.', $segments);
    }

    protected function compileType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'string' => sprintf('VARCHAR(%d)', (int) $column->option('length', 255)),
            'text' => 'TEXT',
            'boolean' => 'TINYINT(1)',
            'timestamp' => 'TIMESTAMP',
            'dateTime' => 'DATETIME',
            'json' => 'JSON',
            'decimal' => sprintf(
                'DECIMAL(%d, %d)',
                (int) $column->option('precision', 8),
                (int) $column->option('scale', 2),
            ),
            'float' => 'DOUBLE',
            default => throw new LogicException(sprintf(
                'Unsupported MySQL schema column type "%s".',
                $column->type(),
            )),
        };
    }
}
