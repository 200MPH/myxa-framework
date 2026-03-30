<?php

declare(strict_types=1);

namespace Myxa\Console;

/**
 * Contract for console commands handled by the framework runner.
 */
interface CommandInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * @return list<InputArgument>
     */
    public function parameters(): array;

    /**
     * @return list<InputOption>
     */
    public function options(): array;

    public function run(ConsoleInput $input, ConsoleOutput $output): int;
}
