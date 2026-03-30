<?php

declare(strict_types=1);

namespace Test\Unit\Console;

use Myxa\Console\ConsoleOutput;
use Myxa\Console\PendingConsoleMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsoleOutput::class)]
#[CoversClass(PendingConsoleMessage::class)]
final class ConsoleOutputTest extends TestCase
{
    public function testPendingMessagesSupportIconsAndDecorators(): void
    {
        $stream = fopen('php://temp', 'w+');
        $message = (new ConsoleOutput($stream))->output('Decorated')
            ->success()
            ->bold()
            ->underline()
            ->strike()
            ->icon();

        unset($message);
        rewind($stream);

        $contents = (string) stream_get_contents($stream);

        self::assertStringContainsString('✔ Decorated', $contents);
        self::assertStringContainsString("\033[32;1;4;9m", $contents);
    }

    public function testInfoMessagesUseLightBlueColor(): void
    {
        $stream = fopen('php://temp', 'w+');
        $message = (new ConsoleOutput($stream))->info('Readable')->icon();

        unset($message);
        rewind($stream);

        $contents = (string) stream_get_contents($stream);

        self::assertStringContainsString("\033[94m", $contents);
        self::assertStringContainsString('ℹ Readable', $contents);
    }

    public function testQuietOutputSuppressesMessages(): void
    {
        $stream = fopen('php://temp', 'w+');
        $message = (new ConsoleOutput($stream, quiet: true))->success('silent')->icon();

        unset($message);
        rewind($stream);

        self::assertSame('', (string) stream_get_contents($stream));
    }

    public function testTableRendersHeadersRowsAndAutoSizedColumns(): void
    {
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream, ansi: false);

        $output->table(
            ['Name', 'Role'],
            [
                ['Wojtek', 'Maintainer'],
                ['Ada', 'QA'],
            ],
        );

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertStringContainsString('+--------+------------+', $contents);
        self::assertStringContainsString('| Name   | Role       |', $contents);
        self::assertStringContainsString('| Wojtek | Maintainer |', $contents);
        self::assertStringContainsString('| Ada    | QA         |', $contents);
    }

    public function testTableSupportsOptionalPaginationControls(): void
    {
        $input = fopen('php://temp', 'r+');
        fwrite($input, "\n3\nq\n");
        rewind($input);

        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream, ansi: false, input: $input);

        $output->table(
            ['Name'],
            [
                ['Ada'],
                ['Wojtek'],
                ['Zoe'],
                ['Mila'],
                ['Noah'],
            ],
            2,
        );

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertStringContainsString('| Ada    |', $contents);
        self::assertStringContainsString('| Wojtek |', $contents);
        self::assertStringContainsString('Page 1/3', $contents);
        self::assertStringContainsString('| Noah   |', $contents);
        self::assertStringContainsString('Page 3/3', $contents);
    }

    public function testTableHeadersRenderWhiteAndBoldWhenAnsiIsEnabled(): void
    {
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream);

        $output->table(['Name'], [['Ada']]);

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertStringContainsString("\033[97;1mName\033[0m", $contents);
    }

    public function testProgressBarRendersInlineWithTimingDetails(): void
    {
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream, ansi: false);

        $output->progressBar(5, 10, 10, 100.0, 110.0);
        $output->progressBar(10, 10, 10, 100.0, 120.0);

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertStringContainsString("\r[#####-----] 5/10  50% elapsed 00:00:10 remaining 00:00:10 total 00:00:20", $contents);
        self::assertStringContainsString("\r[##########] 10/10 100% elapsed 00:00:20 remaining 00:00:00 total 00:00:20", $contents);
        self::assertStringEndsWith(PHP_EOL, $contents);
    }

    public function testProgressTextRendersSingleLineUpdates(): void
    {
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream, ansi: false);

        $output->progressText('Importing', 1, 4, 50.0, 52.0);
        $output->progressText('Importing', 4, 4, 50.0, 58.0);

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertStringContainsString("\rImporting 1/4 (25%) elapsed 00:00:02 remaining 00:00:06 total 00:00:08", $contents);
        self::assertStringContainsString("\rImporting 4/4 (100%) elapsed 00:00:08 remaining 00:00:00 total 00:00:08", $contents);
        self::assertStringEndsWith(PHP_EOL, $contents);
    }
}
