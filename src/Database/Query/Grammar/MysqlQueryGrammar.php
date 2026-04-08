<?php

declare(strict_types=1);

namespace Myxa\Database\Query\Grammar;

final class MysqlQueryGrammar extends AbstractQueryGrammar
{
    protected function containsInvalidQuoteCharacter(string $identifier): bool
    {
        return str_contains($identifier, '`');
    }

    protected function invalidQuoteMessage(): string
    {
        return 'backticks';
    }

    protected function wrapIdentifier(string $identifier): string
    {
        return sprintf('`%s`', $identifier);
    }
}
