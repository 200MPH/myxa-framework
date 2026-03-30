<?php

declare(strict_types=1);

namespace Test\Unit\Console;

use Myxa\Console\Command;
use Myxa\Console\CommandRunner;
use Myxa\Console\ConsoleInput;
use Myxa\Console\ConsoleKernel;
use Myxa\Console\ConsoleOutput;
use Myxa\Console\InputArgument;
use Myxa\Console\InputOption;
use Myxa\Container\Container;
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

    public function testKernelRegistersCommandsAndDelegatesExecution(): void
    {
        $kernel = new ConsoleTestKernel();

        self::assertSame(0, $kernel->handle(['myxa', 'ping']));
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
