<?php

declare(strict_types=1);

namespace Myxa\Database\Query;

use InvalidArgumentException;

/**
 * Raw SQL expression wrapper.
 */
final readonly class RawExpression
{
    public function __construct(private string $value)
    {
        if (trim($this->value) === '') {
            throw new InvalidArgumentException('Raw SQL expression cannot be empty.');
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
