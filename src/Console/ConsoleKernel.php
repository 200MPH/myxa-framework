<?php

declare(strict_types=1);

namespace Myxa\Console;

use Myxa\Container\Container;

/**
 * Small base kernel that registers commands and delegates execution to the runner.
 */
abstract class ConsoleKernel
{
    private readonly CommandRunner $runner;

    /**
     * Create the console kernel and register all configured commands.
     */
    public function __construct(
        ?Container $container = null,
        string $programName = 'myxa',
        string $version = 'dev',
    ) {
        $container ??= new Container();
        $this->runner = new CommandRunner($container, $programName, $version);

        foreach ($this->commands() as $command) {
            $this->runner->register($command);
        }
    }

    /**
     * Return the application console commands.
     *
     * @return iterable<CommandInterface|class-string<CommandInterface>>
     */
    abstract protected function commands(): iterable;

    /**
     * Execute the console kernel with the provided argv input.
     *
     * @param list<string> $argv
     */
    public function handle(array $argv): int
    {
        return $this->runner->run($argv);
    }
}
