<?php

declare(strict_types=1);

namespace Myxa\Console;

/**
 * Immutable parsed CLI input passed to commands.
 */
final readonly class ConsoleInput
{
    /**
     * @param string $command Resolved command name.
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

    /**
     * Return the resolved command name.
     */
    public function command(): string
    {
        return $this->command;
    }

    /**
     * Return a positional parameter value by name.
     */
    public function parameter(string $name, mixed $default = null): mixed
    {
        return $this->parameters[$name] ?? $default;
    }

    /**
     * Return all positional parameters keyed by argument name.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * Return an option value by name.
     */
    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * Return all parsed options keyed by option name.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Indicate whether interactive prompting is enabled.
     */
    public function interactive(): bool
    {
        return $this->interactive;
    }

    /**
     * Indicate whether command output should be suppressed.
     */
    public function quiet(): bool
    {
        return $this->quiet;
    }

    /**
     * Indicate whether help output was requested.
     */
    public function help(): bool
    {
        return $this->help;
    }
}
