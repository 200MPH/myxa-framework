<?php

declare(strict_types=1);

namespace Myxa\Console;

/**
 * Fluent message builder that renders on destruction or explicit send.
 */
final class PendingConsoleMessage
{
    private ?string $level = null;

    private bool $bold = false;

    private bool $underline = false;

    private bool $strike = false;

    private bool $icon = false;

    private bool $sent = false;

    public function __construct(
        private readonly ConsoleOutput $output,
        private readonly string $text,
    ) {
    }

    public function success(): self
    {
        $this->level = 'success';

        return $this;
    }

    public function warning(): self
    {
        $this->level = 'warning';

        return $this;
    }

    public function error(): self
    {
        $this->level = 'error';

        return $this;
    }

    public function info(): self
    {
        $this->level = 'info';

        return $this;
    }

    public function bold(): self
    {
        $this->bold = true;

        return $this;
    }

    public function underline(): self
    {
        $this->underline = true;

        return $this;
    }

    public function strike(): self
    {
        $this->strike = true;

        return $this;
    }

    public function icon(): self
    {
        $this->icon = true;

        return $this;
    }

    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        $this->output->writeRaw($this->render());
        $this->sent = true;
    }

    public function __destruct()
    {
        $this->send();
    }

    private function render(): string
    {
        $text = $this->text;

        if ($this->icon) {
            $icon = match ($this->level) {
                'success' => '✔',
                'error' => '✖',
                'warning' => '!',
                'info' => 'ℹ',
                default => '',
            };

            if ($icon !== '') {
                $text = $icon . ' ' . $text;
            }
        }

        return $this->output->formatStyled(
            $text,
            $this->level,
            $this->bold,
            $this->underline,
            $this->strike,
        );
    }
}
