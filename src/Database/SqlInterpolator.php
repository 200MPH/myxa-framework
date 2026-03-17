<?php

declare(strict_types=1);

namespace Myxa\Database;

use InvalidArgumentException;
use PDO;

/**
 * Utility for interpolating SQL bindings into a query string for debugging.
 */
final class SqlInterpolator
{
    /**
     * @param array<int|string, scalar|null> $bindings
     */
    public static function interpolate(string $sql, array $bindings, ?PDO $pdo = null): string
    {
        [$positionalBindings, $namedBindings] = self::splitBindings($bindings);

        $result = '';
        $length = strlen($sql);
        $position = 0;

        for ($index = 0; $index < $length;) {
            $char = $sql[$index];

            if ($char === '\'' || $char === '"' || $char === '`') {
                $quotedSegment = self::consumeQuotedSegment($sql, $index, $char);
                $result .= $quotedSegment;
                $index += strlen($quotedSegment);

                continue;
            }

            if ($char === '?') {
                if (!array_key_exists($position, $positionalBindings)) {
                    throw new InvalidArgumentException('Not enough positional bindings for SQL placeholders.');
                }

                $result .= self::quoteLiteral($positionalBindings[$position], $pdo);
                $position++;
                $index++;

                continue;
            }

            if ($char === ':' && self::isNamedPlaceholderStart($sql, $index, $length)) {
                $placeholderStart = $index;
                $index += 2;

                while ($index < $length && self::isPlaceholderCharacter($sql[$index])) {
                    $index++;
                }

                $name = substr($sql, $placeholderStart + 1, $index - $placeholderStart - 1);
                if (!array_key_exists($name, $namedBindings)) {
                    throw new InvalidArgumentException(sprintf('Missing named binding for placeholder ":%s".', $name));
                }

                $result .= self::quoteLiteral($namedBindings[$name], $pdo);

                continue;
            }

            $result .= $char;
            $index++;
        }

        if ($position < count($positionalBindings)) {
            throw new InvalidArgumentException('Too many positional bindings were provided for SQL placeholders.');
        }

        return $result;
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     * @return array{0: list<scalar|null>, 1: array<string, scalar|null>}
     */
    private static function splitBindings(array $bindings): array
    {
        $positionalBindings = [];
        $namedBindings = [];

        foreach ($bindings as $key => $value) {
            $normalizedValue = self::normalizeBindingValue($value);

            if (is_int($key)) {
                $positionalBindings[] = $normalizedValue;

                continue;
            }

            $trimmedKey = ltrim(trim($key), ':');
            if ($trimmedKey === '') {
                throw new InvalidArgumentException('Named binding key cannot be empty.');
            }

            $namedBindings[$trimmedKey] = $normalizedValue;
        }

        return [$positionalBindings, $namedBindings];
    }

    /**
     * @return scalar|null
     */
    private static function normalizeBindingValue(mixed $value): string|int|float|bool|null
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('Binding value must be a scalar or null.');
    }

    /**
     * @param scalar|null $value
     */
    private static function quoteLiteral(string|int|float|bool|null $value, ?PDO $pdo): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => self::quoteString($value, $pdo),
        };
    }

    private static function quoteString(string $value, ?PDO $pdo): string
    {
        if ($pdo instanceof PDO) {
            $quoted = $pdo->quote($value);
            if (is_string($quoted)) {
                return $quoted;
            }
        }

        return '\'' . str_replace('\'', '\'\'', $value) . '\'';
    }

    private static function isNamedPlaceholderStart(string $sql, int $index, int $length): bool
    {
        if ($index + 1 >= $length) {
            return false;
        }

        $nextCharacter = $sql[$index + 1];
        if (!self::isPlaceholderStartCharacter($nextCharacter)) {
            return false;
        }

        return !($index > 0 && $sql[$index - 1] === ':');
    }

    private static function isPlaceholderStartCharacter(string $char): bool
    {
        return ctype_alpha($char) || $char === '_';
    }

    private static function isPlaceholderCharacter(string $char): bool
    {
        return ctype_alnum($char) || $char === '_';
    }

    private static function consumeQuotedSegment(string $sql, int $start, string $quote): string
    {
        $length = strlen($sql);
        $index = $start + 1;

        while ($index < $length) {
            if ($sql[$index] === '\\') {
                $index += 2;

                continue;
            }

            if ($sql[$index] === $quote) {
                if (($quote === '\'' || $quote === '"') && $index + 1 < $length && $sql[$index + 1] === $quote) {
                    $index += 2;

                    continue;
                }

                $index++;
                break;
            }

            $index++;
        }

        return substr($sql, $start, $index - $start);
    }
}
