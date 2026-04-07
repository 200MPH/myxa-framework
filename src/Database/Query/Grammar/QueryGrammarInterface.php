<?php

declare(strict_types=1);

namespace Myxa\Database\Query\Grammar;

interface QueryGrammarInterface
{
    public function quoteIdentifier(string $identifier): string;

    /**
     * @param list<string> $columns
     * @return array{0: string, 1: string, 2: ?string}
     */
    public function compileSelectPagination(array $columns, ?int $limit, int $offset, bool $hasOrderBy): array;
}
