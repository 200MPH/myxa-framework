<?php

declare(strict_types=1);

namespace Myxa\Console;

/**
 * Convenience base class for framework console commands.
 */
abstract class Command implements CommandInterface
{
    private ConsoleInput $input;

    private ConsoleOutput $output;

    public function description(): string
    {
        return '';
    }

    public function parameters(): array
    {
        return [];
    }

    public function options(): array
    {
        return [];
    }

    final public function run(ConsoleInput $input, ConsoleOutput $output): int
    {
        $this->input = $input;
        $this->output = $output;

        return $this->handle();
    }

    abstract protected function handle(): int;

    protected function parameter(string $name, mixed $default = null): mixed
    {
        return $this->input->parameter($name, $default);
    }

    protected function option(string $name, mixed $default = null): mixed
    {
        return $this->input->option($name, $default);
    }

    protected function input(): ConsoleInput
    {
        return $this->input;
    }

    protected function output(string $text): PendingConsoleMessage
    {
        return $this->output->output($text);
    }

    protected function success(string $text): PendingConsoleMessage
    {
        return $this->output->success($text);
    }

    protected function warning(string $text): PendingConsoleMessage
    {
        return $this->output->warning($text);
    }

    protected function error(string $text): PendingConsoleMessage
    {
        return $this->output->error($text);
    }

    protected function info(string $text): PendingConsoleMessage
    {
        return $this->output->info($text);
    }

    /**
     * Render a simple table with auto-sized columns.
     *
     * @param list<string> $headers
     * @param list<array<int|string, scalar|null>> $rows
     */
    protected function table(array $headers, array $rows, ?int $pageSize = null): void
    {
        $this->output->table($headers, $rows, $pageSize);
    }

    /**
     * Render a one-line progress bar.
     */
    protected function progressBar(
        int $current,
        int $total,
        int $width = 28,
        ?float $startedAt = null,
        ?float $currentTime = null,
        bool $showElapsed = true,
        bool $showRemaining = true,
        bool $showEstimatedTotal = true,
    ): void {
        $this->output->progressBar(
            $current,
            $total,
            $width,
            $startedAt,
            $currentTime,
            $showElapsed,
            $showRemaining,
            $showEstimatedTotal,
        );
    }

    /**
     * Render one-line text progress.
     */
    protected function progressText(
        string $label,
        int $current,
        int $total,
        ?float $startedAt = null,
        ?float $currentTime = null,
        bool $showElapsed = true,
        bool $showRemaining = true,
        bool $showEstimatedTotal = true,
    ): void {
        $this->output->progressText(
            $label,
            $current,
            $total,
            $startedAt,
            $currentTime,
            $showElapsed,
            $showRemaining,
            $showEstimatedTotal,
        );
    }
}
