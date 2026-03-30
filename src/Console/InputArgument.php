<?php

declare(strict_types=1);

namespace Myxa\Console;

/**
 * Describes a positional command parameter.
 */
final readonly class InputArgument
{
    public function __construct(
        private string $name,
        private string $description = '',
        private bool $required = true,
        private mixed $default = null,
        private ?string $hint = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function required(): bool
    {
        return $this->required;
    }

    public function default(): mixed
    {
        return $this->default;
    }

    public function hint(): ?string
    {
        return $this->hint;
    }
}
