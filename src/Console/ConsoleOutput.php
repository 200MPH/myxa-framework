<?php

declare(strict_types=1);

namespace Myxa\Console;

use InvalidArgumentException;

/**
 * Console output writer with quiet mode and ANSI decoration support.
 */
final class ConsoleOutput
{
    private int $lastInlineWidth = 0;

    /**
     * @param resource $stream
     */
    public function __construct(
        private mixed $stream,
        private readonly bool $quiet = false,
        private readonly bool $ansi = true,
        private mixed $input = null,
    ) {
        $this->input ??= fopen('php://stdin', 'rb');
    }

    public function output(string $text): PendingConsoleMessage
    {
        return new PendingConsoleMessage($this, $text);
    }

    public function success(string $text): PendingConsoleMessage
    {
        return $this->output($text)->success();
    }

    public function warning(string $text): PendingConsoleMessage
    {
        return $this->output($text)->warning();
    }

    public function error(string $text): PendingConsoleMessage
    {
        return $this->output($text)->error();
    }

    public function info(string $text): PendingConsoleMessage
    {
        return $this->output($text)->info();
    }

    /**
     * Apply console styles to text and return the rendered string.
     */
    public function formatStyled(
        string $text,
        ?string $level = null,
        bool $bold = false,
        bool $underline = false,
        bool $strike = false,
    ): string {
        if (!$this->ansi) {
            return $text;
        }

        $codes = [];

        if ($level !== null) {
            $codes[] = match ($level) {
                'success' => '32',
                'warning' => '33',
                'error' => '31',
                'info' => '94',
                'white' => '97',
                default => null,
            };
        }

        if ($bold) {
            $codes[] = '1';
        }

        if ($underline) {
            $codes[] = '4';
        }

        if ($strike) {
            $codes[] = '9';
        }

        $codes = array_values(array_filter($codes, static fn (?string $code): bool => $code !== null));

        if ($codes === []) {
            return $text;
        }

        return sprintf("\033[%sm%s\033[0m", implode(';', $codes), $text);
    }

    /**
     * Render a simple table with auto-sized columns.
     *
     * @param list<string> $headers
     * @param list<array<int|string, scalar|null>> $rows
     */
    public function table(array $headers, array $rows, ?int $pageSize = null): void
    {
        $columnCount = count($headers);

        if ($columnCount === 0) {
            throw new InvalidArgumentException('Console table requires at least one header column.');
        }

        if ($pageSize !== null && $pageSize <= 0) {
            throw new InvalidArgumentException('Console table page size must be greater than zero.');
        }

        $stringHeaders = array_map(static fn (mixed $value): string => (string) $value, $headers);
        $widths = array_map(static fn (string $header): int => strlen($header), $stringHeaders);
        $normalizedRows = [];

        foreach ($rows as $row) {
            $normalizedRow = [];

            foreach ($stringHeaders as $index => $header) {
                $value = $row[$header] ?? $row[$index] ?? '';
                $cell = (string) ($value ?? '');
                $normalizedRow[] = $cell;
                $widths[$index] = max($widths[$index], strlen($cell));
            }

            $normalizedRows[] = $normalizedRow;
        }

        $separator = '+' . implode('+', array_map(
            static fn (int $width): string => str_repeat('-', $width + 2),
            $widths,
        )) . '+';

        if ($pageSize === null || count($normalizedRows) <= $pageSize) {
            $this->renderTablePage($stringHeaders, $normalizedRows, $widths, $separator);

            return;
        }

        $totalPages = (int) ceil(count($normalizedRows) / $pageSize);
        $page = 1;

        while (true) {
            $pageRows = array_slice($normalizedRows, ($page - 1) * $pageSize, $pageSize);
            $this->renderTablePage($stringHeaders, $pageRows, $widths, $separator);
            $this->writeRaw(sprintf(
                'Page %d/%d  [Enter/right: next] [left/p: previous] [number: jump] [q: quit]',
                $page,
                $totalPages,
            ));

            if ($page === $totalPages && $totalPages === 1) {
                return;
            }

            $action = $this->readTablePaginationAction();

            if ($action === 'quit') {
                return;
            }

            if ($action === 'next') {
                if ($page >= $totalPages) {
                    return;
                }

                $page++;

                continue;
            }

            if ($action === 'previous') {
                $page = max(1, $page - 1);

                continue;
            }

            if (is_int($action)) {
                $page = max(1, min($totalPages, $action));
            }
        }
    }

    /**
     * Render a one-line progress bar and update it in place.
     */
    public function progressBar(
        int $current,
        int $total,
        int $width = 28,
        ?float $startedAt = null,
        ?float $currentTime = null,
        bool $showElapsed = true,
        bool $showRemaining = true,
        bool $showEstimatedTotal = true,
    ): void {
        if ($total <= 0) {
            throw new InvalidArgumentException('Progress total must be greater than zero.');
        }

        $current = max(0, min($current, $total));
        $ratio = $current / $total;
        $filled = (int) round($ratio * $width);
        $bar = sprintf(
            '[%s%s] %d/%d %3d%%',
            str_repeat('#', $filled),
            str_repeat('-', max(0, $width - $filled)),
            $current,
            $total,
            (int) round($ratio * 100),
        );

        $details = $this->progressTimingDetails(
            $current,
            $total,
            $startedAt,
            $currentTime,
            $showElapsed,
            $showRemaining,
            $showEstimatedTotal,
        );

        $line = trim($bar . ($details !== '' ? ' ' . $details : ''));

        $this->writeInline($line, complete: $current >= $total);
    }

    /**
     * Render one-line text progress with optional timing estimates.
     */
    public function progressText(
        string $label,
        int $current,
        int $total,
        ?float $startedAt = null,
        ?float $currentTime = null,
        bool $showElapsed = true,
        bool $showRemaining = true,
        bool $showEstimatedTotal = true,
    ): void {
        if ($total <= 0) {
            throw new InvalidArgumentException('Progress total must be greater than zero.');
        }

        $current = max(0, min($current, $total));
        $ratio = $current / $total;
        $line = sprintf(
            '%s %d/%d (%d%%)',
            $label,
            $current,
            $total,
            (int) round($ratio * 100),
        );

        $details = $this->progressTimingDetails(
            $current,
            $total,
            $startedAt,
            $currentTime,
            $showElapsed,
            $showRemaining,
            $showEstimatedTotal,
        );

        if ($details !== '') {
            $line .= ' ' . $details;
        }

        $this->writeInline($line, complete: $current >= $total);
    }

    public function quiet(): bool
    {
        return $this->quiet;
    }

    public function ansi(): bool
    {
        return $this->ansi;
    }

    /**
     * Write output to the underlying stream.
     */
    public function writeRaw(string $text, bool $newline = true, bool $force = false): void
    {
        if ($this->quiet && !$force) {
            return;
        }

        fwrite($this->stream, $newline ? $text . PHP_EOL : $text);
    }

    /**
     * Render an updating single-line output using carriage returns.
     */
    private function writeInline(string $text, bool $complete = false): void
    {
        if ($this->quiet) {
            return;
        }

        $width = strlen($text);
        $padding = $this->lastInlineWidth > $width
            ? str_repeat(' ', $this->lastInlineWidth - $width)
            : '';

        fwrite($this->stream, "\r" . $text . $padding);

        if ($complete) {
            fwrite($this->stream, PHP_EOL);
            $this->lastInlineWidth = 0;

            return;
        }

        $this->lastInlineWidth = max($this->lastInlineWidth, $width);
    }

    /**
     * Render a full table page.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<int> $widths
     */
    private function renderTablePage(array $headers, array $rows, array $widths, string $separator): void
    {
        $this->writeRaw($separator);
        $this->writeRaw($this->formatTableRow($headers, $widths, header: true));
        $this->writeRaw($separator);

        foreach ($rows as $row) {
            $this->writeRaw($this->formatTableRow($row, $widths));
        }

        $this->writeRaw($separator);
    }

    /**
     * Read a table pagination action from the input stream.
     */
    private function readTablePaginationAction(): int|string
    {
        $line = fgets($this->input);
        $line = trim(is_string($line) ? $line : '');

        if ($line === '' || $line === 'n' || $line === "\033[C") {
            return 'next';
        }

        if ($line === 'p' || $line === "\033[D") {
            return 'previous';
        }

        if ($line === 'q') {
            return 'quit';
        }

        if (ctype_digit($line)) {
            return (int) $line;
        }

        return 'next';
    }

    /**
     * @param list<string> $cells
     * @param list<int> $widths
     */
    private function formatTableRow(array $cells, array $widths, bool $header = false): string
    {
        $columns = [];

        foreach ($cells as $index => $cell) {
            $value = str_pad($cell, $widths[$index]);

            if ($header) {
                $value = $this->formatStyled($value, 'white', bold: true);
            }

            $columns[] = ' ' . $value . ' ';
        }

        return '|' . implode('|', $columns) . '|';
    }

    /**
     * Build progress timing details.
     */
    private function progressTimingDetails(
        int $current,
        int $total,
        ?float $startedAt,
        ?float $currentTime,
        bool $showElapsed,
        bool $showRemaining,
        bool $showEstimatedTotal,
    ): string {
        if ($startedAt === null) {
            return '';
        }

        $now = $currentTime ?? microtime(true);
        $elapsed = max(0.0, $now - $startedAt);
        $estimatedTotal = $current > 0 ? ($elapsed / $current) * $total : null;
        $remaining = $estimatedTotal !== null ? max(0.0, $estimatedTotal - $elapsed) : null;
        $parts = [];

        if ($showElapsed) {
            $parts[] = 'elapsed ' . $this->formatDuration($elapsed);
        }

        if ($showRemaining && $remaining !== null) {
            $parts[] = 'remaining ' . $this->formatDuration($remaining);
        }

        if ($showEstimatedTotal && $estimatedTotal !== null) {
            $parts[] = 'total ' . $this->formatDuration($estimatedTotal);
        }

        return implode(' ', $parts);
    }

    /**
     * Format a duration as HH:MM:SS.
     */
    private function formatDuration(float $seconds): string
    {
        $seconds = (int) round($seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }
}
