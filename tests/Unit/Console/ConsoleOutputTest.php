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

    public function testPendingMessagesSupportAllLevelsAndExplicitSendDoesNotDuplicateOutput(): void
    {
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream);

        $warning = $output->warning('Careful')->icon();
        $warning->send();
        $warning->send();

        $error = $output->error('Broken')->icon();
        unset($error);

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertSame(1, substr_count($contents, '! Careful'));
        self::assertStringContainsString("\033[33m! Careful\033[0m", $contents);
        self::assertStringContainsString("\033[31m✖ Broken\033[0m", $contents);
    }

    public function testMessagesCanRenderPlainTextWhenNoStylesAreApplied(): void
    {
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream, ansi: false);
        $message = $output->output('Plain text')->icon();

        unset($message);
        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertSame("Plain text" . PHP_EOL, $contents);
        self::assertSame('unstyled', $output->formatStyled('unstyled', 'unknown'));
    }

    public function testQuietOutputSuppressesMessages(): void
    {
        $stream = fopen('php://temp', 'w+');
        $message = (new ConsoleOutput($stream, quiet: true))->success('silent')->icon();

        unset($message);
        rewind($stream);

        self::assertSame('', (string) stream_get_contents($stream));
    }

    public function testWriteRawCanForceOutputInQuietModeAndExposeFlags(): void
    {
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream, quiet: true, ansi: false);

        $output->writeRaw('forced', force: true);

        rewind($stream);

        self::assertTrue($output->quiet());
        self::assertFalse($output->ansi());
        self::assertSame("forced" . PHP_EOL, (string) stream_get_contents($stream));
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

    public function testTablePaginationSupportsPreviousAndArrowControls(): void
    {
        $input = fopen('php://temp', 'r+');
        fwrite($input, "\033[C\n\033[D\nq\n");
        rewind($input);

        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream, ansi: false, input: $input);

        $output->table(
            ['Name'],
            [
                ['Ada'],
                ['Wojtek'],
                ['Zoe'],
            ],
            1,
        );

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertGreaterThanOrEqual(2, substr_count($contents, 'Page 1/3'));
        self::assertStringContainsString('Page 2/3', $contents);
        self::assertStringContainsString('| Wojtek |', $contents);
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

    public function testTableRejectsInvalidConfiguration(): void
    {
        $output = new ConsoleOutput(fopen('php://temp', 'w+'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Console table requires at least one header column.');

        $output->table([], []);
    }

    public function testTableRejectsNonPositivePageSize(): void
    {
        $output = new ConsoleOutput(fopen('php://temp', 'w+'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Console table page size must be greater than zero.');

        $output->table(['Name'], [['Ada']], 0);
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

    public function testProgressHelpersCanRenderWithoutTimingDetails(): void
    {
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream, ansi: false);

        $output->progressBar(1, 2, 4);
        $output->progressText('Syncing', 2, 2);

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertStringContainsString("\r[##--] 1/2  50%", $contents);
        self::assertStringContainsString("\rSyncing 2/2 (100%)", $contents);
        self::assertStringNotContainsString('elapsed', $contents);
    }

    public function testProgressHelpersRejectInvalidTotals(): void
    {
        $output = new ConsoleOutput(fopen('php://temp', 'w+'));

        try {
            $output->progressBar(0, 0);
            self::fail('Expected invalid total exception for progress bar.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Progress total must be greater than zero.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Progress total must be greater than zero.');

        $output->progressText('Importing', 0, 0);
    }
}
