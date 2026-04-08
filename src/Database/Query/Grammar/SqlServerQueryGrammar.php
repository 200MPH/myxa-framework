<?php

declare(strict_types=1);

namespace Myxa\Database\Query\Grammar;

final class SqlServerQueryGrammar extends AbstractQueryGrammar
{
    /**
     * @param list<string> $columns
     * @return array{0: string, 1: string, 2: ?string}
     */
    public function compileSelectPagination(array $columns, ?int $limit, int $offset, bool $hasOrderBy): array
    {
        $columnSql = implode(', ', $columns);

        if ($limit === null) {
            return [$columnSql, '', null];
        }

        if ($offset === 0) {
            return [sprintf('TOP %d %s', $limit, $columnSql), '', null];
        }

        return [
            $columnSql,
            sprintf('OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $offset, $limit),
            $hasOrderBy ? null : '(SELECT 0)',
        ];
    }

    protected function containsInvalidQuoteCharacter(string $identifier): bool
    {
        return str_contains($identifier, '[') || str_contains($identifier, ']');
    }

    protected function invalidQuoteMessage(): string
    {
        return 'square brackets';
    }

    protected function wrapIdentifier(string $identifier): string
    {
        return sprintf('[%s]', $identifier);
    }
}
