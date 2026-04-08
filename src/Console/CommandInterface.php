<?php

declare(strict_types=1);

namespace Myxa\Console;

/**
 * Contract for console commands handled by the framework runner.
 */
interface CommandInterface
{
    /**
     * Return the unique command name used on the CLI.
     */
    public function name(): string;

    /**
     * Return a short human-readable command summary.
     */
    public function description(): string;

    /**
     * Return the positional parameters accepted by the command.
     *
     * @return list<InputArgument>
     */
    public function parameters(): array;

    /**
     * Return the long-form options accepted by the command.
     *
     * @return list<InputOption>
     */
    public function options(): array;

    /**
     * Execute the command using parsed input and the configured output writer.
     */
    public function run(ConsoleInput $input, ConsoleOutput $output): int;
}
