<?php

declare(strict_types=1);

namespace Myxa\Console;

use InvalidArgumentException;
use Myxa\Container\Container;
use Throwable;

/**
 * Parses CLI input, resolves commands, and coordinates execution.
 */
final class CommandRunner
{
    /**
     * @var array<string, CommandInterface>
     */
    private array $commands = [];

    /**
     * Create the console runner with its DI container and terminal streams.
     *
     * @param resource|null $input
     * @param resource|null $output
     */
    public function __construct(
        private readonly Container $container,
        private readonly string $programName = 'myxa',
        private readonly string $version = 'dev',
        private mixed $input = null,
        private mixed $output = null,
    ) {
        $this->input ??= fopen('php://stdin', 'rb');
        $this->output ??= fopen('php://stdout', 'wb');
    }

    /**
     * Register a command instance or command class.
     *
     * @param CommandInterface|class-string<CommandInterface> $command
     */
    public function register(CommandInterface|string $command): self
    {
        $instance = $this->resolveCommand($command);
        $name = trim($instance->name());

        if ($name === '') {
            throw new InvalidArgumentException('Console command name cannot be empty.');
        }

        $this->commands[$name] = $instance;

        return $this;
    }

    /**
     * Return all registered commands ordered by name.
     *
     * @return array<string, CommandInterface>
     */
    public function commands(): array
    {
        ksort($this->commands);

        return $this->commands;
    }

    /**
     * Run the console program with the provided argv values.
     *
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $argv = array_values($argv);
        $program = basename((string) array_shift($argv) ?: $this->programName);
        [$commandName, $tokens] = $this->extractCommandName($argv);
        [$positionals, $options] = $this->parseTokens($tokens);

        $quiet = $this->toBool($options['quiet'] ?? false);
        $interactive = $this->toBool($options['interactive'] ?? false);
        $help = $this->toBool($options['help'] ?? false);
        $version = $this->toBool($options['version'] ?? false);

        $output = new ConsoleOutput($this->output, quiet: $quiet, input: $this->input);

        if ($version && $commandName === null) {
            $output->info(sprintf('%s %s', $program, $this->version))->icon();

            return 0;
        }

        if ($commandName === null || $commandName === 'list') {
            return $help || $commandName === null || $commandName === 'list'
                ? $this->renderGlobalHelp($output, $program)
                : 0;
        }

        if ($commandName === 'help') {
            $targetCommand = $positionals[0] ?? null;

            return $this->renderCommandHelp($output, $program, is_string($targetCommand) ? $targetCommand : null);
        }

        $command = $this->commands[$commandName] ?? null;
        if (!$command instanceof CommandInterface) {
            if (!$quiet) {
                $output->error(sprintf('Command [%s] was not found.', $commandName))->icon();
            }

            return 1;
        }

        if ($help) {
            return $this->renderCommandHelp($output, $program, $commandName);
        }

        try {
            $arguments = $this->resolveArguments($command, $positionals, $output, $interactive);
            $commandOptions = $this->resolveOptions($command, $options, $output, $interactive);
        } catch (InvalidArgumentException $exception) {
            if (!$quiet) {
                $output->error($exception->getMessage())->icon();
            }

            return 1;
        }

        try {
            $exitCode = $command->run(
                new ConsoleInput(
                    $commandName,
                    $arguments,
                    $commandOptions,
                    $interactive,
                    $quiet,
                    $help,
                ),
                $output,
            );
        } catch (Throwable $exception) {
            if (!$quiet) {
                $output->error($exception->getMessage())->icon();
            }

            return 1;
        }

        return $quiet ? ($exitCode === 0 ? 0 : 1) : $exitCode;
    }

    /**
     * Render general program help and the registered command list.
     */
    private function renderGlobalHelp(ConsoleOutput $output, string $program): int
    {
        $output->output(sprintf('Usage: %s [command] [...parameters] [...--option=value]', $program))->bold();
        $output->output('Built-in options:');
        $output->output('  --help        Show command help and parameter descriptions');
        $output->output('  --interactive Prompt for missing parameters and options');
        $output->output('  --quiet       Suppress output and return a pipeline-friendly status');
        $output->output('  --version     Show the CLI version');
        $output->output('');
        $output->output('Commands:')->bold();

        foreach ($this->commands() as $name => $command) {
            $output->writeRaw(sprintf(
                '  %s %s',
                $output->formatStyled(str_pad($name, 18), 'warning'),
                $command->description(),
            ));
        }

        $output->output('');
        $output->output(sprintf(
            'Use `%s help <command>` or `%s <command> --help` for details.',
            $program,
            $program,
        ))->info();

        return 0;
    }

    /**
     * Render help output for a single command.
     */
    private function renderCommandHelp(ConsoleOutput $output, string $program, ?string $commandName): int
    {
        if ($commandName === null) {
            return $this->renderGlobalHelp($output, $program);
        }

        $command = $this->commands[$commandName] ?? null;
        if (!$command instanceof CommandInterface) {
            $output->error(sprintf('Command [%s] was not found.', $commandName))->icon();

            return 1;
        }

        $usage = sprintf('%s %s', $program, $command->name());
        foreach ($command->parameters() as $parameter) {
            $usage .= $parameter->required()
                ? sprintf(' <%s>', $parameter->name())
                : sprintf(' [%s]', $parameter->name());
        }

        if ($command->options() !== []) {
            $usage .= ' [options]';
        }

        $output->output($command->name())->bold();
        $output->output($command->description());
        $output->output('');
        $output->output('Usage:')->bold();
        $output->output('  ' . $usage);

        if ($command->parameters() !== []) {
            $output->output('');
            $output->output('Parameters:')->bold();

            foreach ($command->parameters() as $parameter) {
                $required = $parameter->required() ? 'required' : 'optional';
                $hint = $parameter->hint() !== null ? sprintf(' Hint: %s', $parameter->hint()) : '';
                $output->output(sprintf(
                    '  %-18s %s (%s)%s',
                    $parameter->name(),
                    $parameter->description(),
                    $required,
                    $hint,
                ));
            }
        }

        if ($command->options() !== []) {
            $output->output('');
            $output->output('Options:')->bold();

            foreach ($command->options() as $option) {
                $required = $option->required() ? 'required' : 'optional';
                $value = $option->acceptsValue() ? '=value' : '';
                $hint = $option->hint() !== null ? sprintf(' Hint: %s', $option->hint()) : '';
                $output->output(sprintf(
                    '  --%-16s %s (%s)%s',
                    $option->name() . $value,
                    $option->description(),
                    $required,
                    $hint,
                ));
            }
        }

        return 0;
    }

    /**
     * Extract the command name from argv and leave remaining command tokens.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function extractCommandName(array $argv): array
    {
        $tokens = [];
        $commandName = null;

        foreach ($argv as $token) {
            if ($commandName === null && !str_starts_with($token, '--')) {
                $commandName = $token;
                continue;
            }

            $tokens[] = $token;
        }

        return [$commandName, $tokens];
    }

    /**
     * Parse CLI tokens into positional arguments and long options.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function parseTokens(array $tokens): array
    {
        $positionals = [];
        $options = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (!str_starts_with($token, '--')) {
                $positionals[] = $token;

                continue;
            }

            $option = substr($token, 2);
            if ($option === '') {
                continue;
            }

            if (str_contains($option, '=')) {
                [$name, $value] = explode('=', $option, 2);
                $options[$name] = $value;

                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            if (is_string($next) && !str_starts_with($next, '--')) {
                $options[$option] = $next;
                $index++;

                continue;
            }

            $options[$option] = true;
        }

        return [$positionals, $options];
    }

    /**
     * Resolve positional values into named command parameters.
     *
     * @return array<string, mixed>
     */
    private function resolveArguments(
        CommandInterface $command,
        array $positionals,
        ConsoleOutput $output,
        bool $interactive,
    ): array {
        $arguments = [];

        foreach ($command->parameters() as $index => $parameter) {
            $value = $positionals[$index] ?? null;

            if (($value === null || $value === '') && $interactive) {
                $value = $this->prompt($output, sprintf(
                    '%s%s: ',
                    $parameter->description() !== '' ? $parameter->description() : $parameter->name(),
                    $parameter->hint() !== null ? sprintf(' (%s)', $parameter->hint()) : '',
                ));
            }

            if (($value === null || $value === '') && $parameter->required()) {
                throw new InvalidArgumentException(sprintf('Missing required parameter [%s].', $parameter->name()));
            }

            $arguments[$parameter->name()] = $value ?? $parameter->default();
        }

        if (count($positionals) > count($command->parameters())) {
            throw new InvalidArgumentException(sprintf(
                'Too many parameters were provided for command [%s].',
                $command->name(),
            ));
        }

        return $arguments;
    }

    /**
     * Resolve command options against their definitions.
     *
     * @param array<string, mixed> $parsedOptions
     *
     * @return array<string, mixed>
     */
    private function resolveOptions(
        CommandInterface $command,
        array $parsedOptions,
        ConsoleOutput $output,
        bool $interactive,
    ): array {
        $options = [];

        foreach ($command->options() as $option) {
            $value = $parsedOptions[$option->name()] ?? null;

            if (($value === null || $value === '') && $interactive && $option->required()) {
                $value = $this->prompt($output, sprintf(
                    '--%s%s: ',
                    $option->name(),
                    $option->hint() !== null ? sprintf(' (%s)', $option->hint()) : '',
                ));
            }

            if (($value === null || $value === '') && $option->required()) {
                throw new InvalidArgumentException(sprintf('Missing required option [--%s].', $option->name()));
            }

            if ($option->acceptsValue()) {
                $options[$option->name()] = $value ?? $option->default();

                continue;
            }

            $options[$option->name()] = $this->toBool($value ?? $option->default());
        }

        return $options;
    }

    /**
     * Prompt for interactive input.
     */
    private function prompt(ConsoleOutput $output, string $prompt): string
    {
        $output->writeRaw($prompt, force: true);
        $line = fgets($this->input);

        return trim(is_string($line) ? $line : '');
    }

    /**
     * Resolve a command instance through the container when needed.
     *
     * @param CommandInterface|class-string<CommandInterface> $command
     */
    private function resolveCommand(CommandInterface|string $command): CommandInterface
    {
        if ($command instanceof CommandInterface) {
            return $command;
        }

        $instance = $this->container->make($command);

        if (!$instance instanceof CommandInterface) {
            throw new InvalidArgumentException(sprintf(
                'Console command [%s] must implement %s.',
                $command,
                CommandInterface::class,
            ));
        }

        return $instance;
    }

    /**
     * Convert mixed CLI values into booleans.
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return !in_array(strtolower($value), ['0', 'false', 'no', 'off', ''], true);
        }

        return $value !== null;
    }
}
