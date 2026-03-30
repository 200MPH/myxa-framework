<?php

declare(strict_types=1);

namespace Test\Unit\Console;

use Myxa\Console\Command;
use Myxa\Console\CommandRunner;
use Myxa\Console\ConsoleInput;
use Myxa\Console\ConsoleKernel;
use Myxa\Console\ConsoleOutput;
use Myxa\Console\CommandInterface;
use Myxa\Console\InputArgument;
use Myxa\Console\InputOption;
use Myxa\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Command::class)]
#[CoversClass(CommandRunner::class)]
#[CoversClass(ConsoleInput::class)]
#[CoversClass(ConsoleKernel::class)]
#[CoversClass(ConsoleOutput::class)]
#[CoversClass(InputArgument::class)]
#[CoversClass(InputOption::class)]
final class CommandRunnerTest extends TestCase
{
    public function testRunnerExecutesCommandWithParametersAndOptions(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.0.0', $input, $output);
        $runner->register(new ConsoleTestGreetingCommand());

        $exitCode = $runner->run(['myxa', 'greet', 'Wojtek', '--title=Captain']);

        rewind($output);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Hello Captain Wojtek', (string) stream_get_contents($output));
    }

    public function testRunnerSupportsInteractivePromptingForMissingParametersAndOptions(): void
    {
        $input = fopen('php://temp', 'r+');
        fwrite($input, "Ada\nEngineer\n");
        rewind($input);

        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.0.0', $input, $output);
        $runner->register(new ConsoleTestGreetingCommand());

        $exitCode = $runner->run(['myxa', 'greet', '--interactive']);

        rewind($output);
        $contents = (string) stream_get_contents($output);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Who should be greeted? (Name)', $contents);
        self::assertStringContainsString('--title (Title)', $contents);
        self::assertStringContainsString('Hello Engineer Ada', $contents);
    }

    public function testRunnerShowsCommandHelpWithDescriptions(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.0.0', $input, $output);
        $runner->register(new ConsoleTestGreetingCommand());

        $exitCode = $runner->run(['myxa', 'greet', '--help']);

        rewind($output);
        $contents = (string) stream_get_contents($output);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Usage:', $contents);
        self::assertStringContainsString('who', $contents);
        self::assertStringContainsString('--title=value', $contents);
    }

    public function testQuietModeSuppressesOutputAndNormalizesFailureExitCode(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.0.0', $input, $output);
        $runner->register(new ConsoleTestFailingCommand());

        $exitCode = $runner->run(['myxa', 'fail', '--quiet']);

        rewind($output);

        self::assertSame(1, $exitCode);
        self::assertSame('', (string) stream_get_contents($output));
    }

    public function testGlobalHelpHighlightsCommandNamesInYellow(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.0.0', $input, $output);
        $runner->register(new ConsoleTestGreetingCommand());

        $exitCode = $runner->run(['myxa']);

        rewind($output);
        $contents = (string) stream_get_contents($output);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString("\033[33mgreet", $contents);
        self::assertStringContainsString('Greets a user with an optional title.', $contents);
    }

    public function testListCommandAliasAndMissingHelpTargetAreHandled(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.0.0', $input, $output);
        $runner->register(new ConsoleTestGreetingCommand());

        self::assertSame(0, $runner->run(['myxa', 'list']));
        self::assertSame(1, $runner->run(['myxa', 'help', 'missing']));

        rewind($output);
        $contents = (string) stream_get_contents($output);

        self::assertStringContainsString('Commands:', $contents);
        self::assertStringContainsString('Command [missing] was not found.', $contents);
    }

    public function testKernelRegistersCommandsAndDelegatesExecution(): void
    {
        $kernel = new ConsoleTestKernel();

        self::assertSame(0, $kernel->handle(['myxa', 'ping']));
    }

    public function testRunnerSupportsVersionGlobalHelpAndNamedHelpCommand(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.2.3', $input, $output);
        $runner->register(new ConsoleTestGreetingCommand());

        self::assertSame(0, $runner->run(['myxa', '--version']));
        self::assertSame(0, $runner->run(['myxa']));
        self::assertSame(0, $runner->run(['myxa', 'help', 'greet']));

        rewind($output);
        $contents = (string) stream_get_contents($output);

        self::assertStringContainsString('myxa 1.2.3', $contents);
        self::assertStringContainsString('Built-in options:', $contents);
        self::assertStringContainsString('Greets a user with an optional title.', $contents);
    }

    public function testRunnerHandlesMissingCommandsAndValidationErrors(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.0.0', $input, $output);
        $runner->register(new ConsoleTestStrictCommand());

        self::assertSame(1, $runner->run(['myxa', 'missing']));
        self::assertSame(1, $runner->run(['myxa', 'strict']));
        self::assertSame(1, $runner->run(['myxa', 'strict', 'Ada', 'extra', '--role=admin']));
        self::assertSame(1, $runner->run(['myxa', 'strict', 'Ada']));

        rewind($output);
        $contents = (string) stream_get_contents($output);

        self::assertStringContainsString('Command [missing] was not found.', $contents);
        self::assertStringContainsString('Missing required parameter [name].', $contents);
        self::assertStringContainsString('Too many parameters were provided for command [strict].', $contents);
        self::assertStringContainsString('Missing required option [--role].', $contents);
    }

    public function testQuietModeSuppressesUnknownCommandErrors(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.0.0', $input, $output);

        self::assertSame(1, $runner->run(['myxa', 'missing', '--quiet']));

        rewind($output);

        self::assertSame('', (string) stream_get_contents($output));
    }

    public function testRunnerResolvesCommandClassNamesAndBooleanOptions(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $container = new Container();
        $runner = new CommandRunner($container, 'myxa', '1.0.0', $input, $output);

        $runner->register(ConsoleTestContainerCommand::class);

        self::assertSame(0, $runner->run(['myxa', 'container', '--force=false']));

        rewind($output);
        $contents = (string) stream_get_contents($output);

        self::assertStringContainsString('force=off', $contents);
        self::assertArrayHasKey('container', $runner->commands());
    }

    public function testRunnerTreatsVersionAsNormalOptionWhenCommandIsProvided(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.2.3', $input, $output);
        $runner->register(new ConsoleTestGreetingCommand());

        self::assertSame(0, $runner->run(['myxa', 'greet', 'Ada', '--title=Engineer', '--version']));

        rewind($output);
        $contents = (string) stream_get_contents($output);

        self::assertStringContainsString('Hello Engineer Ada', $contents);
        self::assertStringNotContainsString('myxa 1.2.3', $contents);
    }

    public function testRunnerSupportsMultipleFalseLikeBooleanOptionValues(): void
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        $runner = new CommandRunner(new Container(), 'myxa', '1.0.0', $input, $output);
        $runner->register(ConsoleTestContainerCommand::class);

        self::assertSame(0, $runner->run(['myxa', 'container', '--force=0']));
        self::assertSame(0, $runner->run(['myxa', 'container', '--force=off']));
        self::assertSame(0, $runner->run(['myxa', 'container', '--force=no']));

        rewind($output);
        $contents = (string) stream_get_contents($output);

        self::assertSame(3, substr_count($contents, 'force=off'));
    }

    public function testRunnerRejectsInvalidRegisteredCommands(): void
    {
        $runner = new CommandRunner(new Container());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Console command [%s] must implement %s.',
            ConsoleTestInvalidCommand::class,
            CommandInterface::class,
        ));

        $runner->register(ConsoleTestInvalidCommand::class);
    }

    public function testRunnerRejectsCommandsWithEmptyNames(): void
    {
        $runner = new CommandRunner(new Container());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Console command name cannot be empty.');

        $runner->register(new class extends Command {
            public function name(): string
            {
                return '   ';
            }

            protected function handle(): int
            {
                return 0;
            }
        });
    }
}

final class ConsoleTestGreetingCommand extends Command
{
    public function name(): string
    {
        return 'greet';
    }

    public function description(): string
    {
        return 'Greets a user with an optional title.';
    }

    public function parameters(): array
    {
        return [
            new InputArgument('who', 'Who should be greeted?', hint: 'Name'),
        ];
    }

    public function options(): array
    {
        return [
            new InputOption('title', 'Optional greeting title', acceptsValue: true, required: true, hint: 'Title'),
        ];
    }

    protected function handle(): int
    {
        $this->info(sprintf(
            'Hello %s %s',
            $this->option('title'),
            $this->parameter('who'),
        ))->icon();

        return 0;
    }
}

final class ConsoleTestFailingCommand extends Command
{
    public function name(): string
    {
        return 'fail';
    }

    protected function handle(): int
    {
        $this->error('This should be hidden in quiet mode.');

        return 7;
    }
}

final class ConsoleTestKernel extends ConsoleKernel
{
    protected function commands(): iterable
    {
        return [
            new class extends Command {
                public function name(): string
                {
                    return 'ping';
                }

                protected function handle(): int
                {
                    return 0;
                }
            },
        ];
    }
}

final class ConsoleTestStrictCommand extends Command
{
    public function name(): string
    {
        return 'strict';
    }

    public function parameters(): array
    {
        return [
            new InputArgument('name', 'User name'),
        ];
    }

    public function options(): array
    {
        return [
            new InputOption('role', 'Required role', acceptsValue: true, required: true),
        ];
    }

    protected function handle(): int
    {
        return 0;
    }
}

final class ConsoleTestContainerCommand extends Command
{
    public function __construct(private readonly ConsoleTestDependency $dependency)
    {
    }

    public function name(): string
    {
        return 'container';
    }

    public function options(): array
    {
        return [
            new InputOption('force', 'Force flag', acceptsValue: false, default: true),
        ];
    }

    protected function handle(): int
    {
        $this->output(sprintf(
            '%s force=%s',
            $this->dependency->label,
            $this->option('force') ? 'on' : 'off',
        ));

        return 0;
    }
}

final readonly class ConsoleTestDependency
{
    public function __construct(public string $label = 'resolved')
    {
    }
}

final class ConsoleTestInvalidCommand
{
}
