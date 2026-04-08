<?php

declare(strict_types=1);

namespace Myxa\Console;

/**
 * Describes a long-form command option.
 */
final readonly class InputOption
{
    /**
     * @param string $name Long-form option name without the leading dashes.
     * @param string $description Human-readable option description.
     * @param bool $acceptsValue Whether the option expects a value.
     * @param bool $required Whether the option must be provided.
     * @param mixed $default Default value used when the option is omitted.
     * @param string|null $hint Optional short prompt/help hint.
     */
    public function __construct(
        private string $name,
        private string $description = '',
        private bool $acceptsValue = false,
        private bool $required = false,
        private mixed $default = null,
        private ?string $hint = null,
    ) {
    }

    /**
     * Return the option name without leading dashes.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the human-readable option description.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Indicate whether the option expects a value.
     */
    public function acceptsValue(): bool
    {
        return $this->acceptsValue;
    }

    /**
     * Indicate whether the option is required.
     */
    public function required(): bool
    {
        return $this->required;
    }

    /**
     * Return the default value used when the option is omitted.
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
