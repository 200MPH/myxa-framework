<?php

declare(strict_types=1);

namespace Myxa\Console;

/**
 * Immutable parsed CLI input passed to commands.
 */
final readonly class ConsoleInput
{
    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $options
     */
    public function __construct(
        private string $command,
        private array $parameters,
        private array $options,
        private bool $interactive = false,
        private bool $quiet = false,
        private bool $help = false,
    ) {
    }

    public function command(): string
    {
        return $this->command;
    }

    public function parameter(string $name, mixed $default = null): mixed
    {
        return $this->parameters[$name] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function interactive(): bool
    {
        return $this->interactive;
    }

    public function quiet(): bool
    {
        return $this->quiet;
    }

    public function help(): bool
    {
        return $this->help;
    }
}
