<?php

declare(strict_types=1);

namespace Myxa\Console;

/**
 * Describes a positional command parameter.
 */
final readonly class InputArgument
{
    /**
     * @param string $name Parameter name used to resolve input values.
     * @param string $description Human-readable parameter description.
     * @param bool $required Whether the parameter must be provided.
     * @param mixed $default Default value used when the parameter is omitted.
     * @param string|null $hint Optional short prompt/help hint.
     */
    public function __construct(
        private string $name,
        private string $description = '',
        private bool $required = true,
        private mixed $default = null,
        private ?string $hint = null,
    ) {
    }

    /**
     * Return the parameter name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the human-readable parameter description.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Indicate whether the parameter is required.
     */
    public function required(): bool
    {
        return $this->required;
    }

    /**
     * Return the default value used when the parameter is omitted.
     */
    public function default(): mixed
    {
        return $this->default;
    }

    /**
     * Return the optional prompt/help hint.
     */
    public function hint(): ?string
    {
        return $this->hint;
    }
}
