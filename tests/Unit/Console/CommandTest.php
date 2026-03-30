<?php

declare(strict_types=1);

namespace Test\Unit\Console;

use Myxa\Console\Command;
use Myxa\Console\ConsoleInput;
use Myxa\Console\ConsoleOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Command::class)]
final class CommandTest extends TestCase
{
    public function testCommandDefaultMetadataAndHelperMethodsAreAvailable(): void
    {
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream, ansi: false, input: fopen('php://temp', 'r+'));
        $command = new ConsoleTestInspectableCommand();

        $exitCode = $command->run(
            new ConsoleInput('inspect', ['name' => 'Ada'], ['role' => 'admin']),
            $output,
        );

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertSame('', $command->description());
        self::assertSame([], $command->parameters());
        self::assertSame([], $command->options());
        self::assertSame(0, $exitCode);
        self::assertSame('Ada', $command->seenParameter);
        self::assertSame('admin', $command->seenOption);
        self::assertSame('inspect', $command->seenCommandName);
        self::assertStringContainsString('plain output', $contents);
        self::assertStringContainsString('ok', $contents);
        self::assertStringContainsString('warn', $contents);
        self::assertStringContainsString('bad', $contents);
        self::assertStringContainsString('info', $contents);
        self::assertStringContainsString('| Name |', $contents);
        self::assertStringContainsString("\r[#####] 1/1 100%", $contents);
        self::assertStringContainsString("\rDone 1/1 (100%)", $contents);
    }
}

final class ConsoleTestInspectableCommand extends Command
{
    public string $seenParameter = '';

    public string $seenOption = '';

    public string $seenCommandName = '';

    public function name(): string
    {
        return 'inspect';
    }

    protected function handle(): int
    {
        $this->seenParameter = (string) $this->parameter('name');
        $this->seenOption = (string) $this->option('role');
        $this->seenCommandName = $this->input()->command();

        $this->output('plain output');
        $this->success('ok');
        $this->warning('warn');
        $this->error('bad');
        $this->info('info');
        $this->table(['Name'], [['Ada']]);
        $this->progressBar(1, 1, 5, 100.0, 100.0);
        $this->progressText('Done', 1, 1, 100.0, 100.0);

        return 0;
    }
}
