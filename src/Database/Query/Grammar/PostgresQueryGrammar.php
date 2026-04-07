<?php

declare(strict_types=1);

namespace Myxa\Database\Query\Grammar;

final class PostgresQueryGrammar extends AbstractQueryGrammar
{
    protected function containsInvalidQuoteCharacter(string $identifier): bool
    {
        return str_contains($identifier, '"');
    }

    protected function invalidQuoteMessage(): string
    {
        return 'double quotes';
    }

    protected function wrapIdentifier(string $identifier): string
    {
        return sprintf('"%s"', $identifier);
    }
}
