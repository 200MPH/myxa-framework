<?php

declare(strict_types=1);

namespace Myxa\Database\Query\Grammar;

use InvalidArgumentException;

abstract class AbstractQueryGrammar implements QueryGrammarInterface
{
    public function compileSelectPagination(array $columns, ?int $limit, int $offset, bool $hasOrderBy): array
    {
        $suffix = '';

        if ($limit !== null) {
            $suffix = sprintf('LIMIT %d', $limit);

            if ($offset > 0) {
                $suffix .= sprintf(' OFFSET %d', $offset);
            }
        }

        return [implode(', ', $columns), $suffix, null];
    }

    public function quoteIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new InvalidArgumentException('Identifier cannot be empty.');
        }

        if ($this->containsInvalidQuoteCharacter($identifier)) {
            throw new InvalidArgumentException(sprintf(
                'Identifier cannot contain %s.',
                $this->invalidQuoteMessage(),
            ));
        }

        $parts = explode('.', $identifier);
        $quotedParts = array_map(
            function (string $part): string {
                $part = trim($part);
                if ($part === '') {
                    throw new InvalidArgumentException('Identifier contains an empty segment.');
                }

                return $this->wrapIdentifier($part);
            },
            $parts,
        );

        return implode('.', $quotedParts);
    }

    abstract protected function containsInvalidQuoteCharacter(string $identifier): bool;

    abstract protected function invalidQuoteMessage(): string;

    abstract protected function wrapIdentifier(string $identifier): string;
}
