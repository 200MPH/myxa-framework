<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\Grammar;

use LogicException;
use Myxa\Database\Schema\ColumnDefinition;

/**
 * PostgreSQL-flavoured schema grammar.
 */
final class PostgresSchemaGrammar extends AbstractSchemaGrammar
{
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
        if ($column->isAutoIncrement()) {
            return match ($column->type()) {
                'integer' => 'SERIAL',
                'bigInteger' => 'BIGSERIAL',
                default => throw new LogicException(sprintf(
                    'Unsupported PostgreSQL auto-increment schema column type "%s".',
                    $column->type(),
                )),
            };
        }

        return match ($column->type()) {
            'integer' => 'INTEGER',
            'bigInteger' => 'BIGINT',
            'string' => sprintf('VARCHAR(%d)', (int) $column->option('length', 255)),
            'text' => 'TEXT',
            'boolean' => 'BOOLEAN',
            'timestamp' => 'TIMESTAMP',
            'dateTime' => 'TIMESTAMP',
            'json' => 'JSONB',
            'decimal' => sprintf(
                'DECIMAL(%d, %d)',
                (int) $column->option('precision', 8),
                (int) $column->option('scale', 2),
            ),
            'float' => 'DOUBLE PRECISION',
            default => throw new LogicException(sprintf(
                'Unsupported PostgreSQL schema column type "%s".',
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
}
