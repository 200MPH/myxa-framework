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

    /**
     * Create a fluent message builder for the provided text.
     */
    public function __construct(
        private readonly ConsoleOutput $output,
        private readonly string $text,
    ) {
    }

    /**
     * Mark the message as successful and render it in success styling.
     */
    public function success(): self
    {
        $this->level = 'success';

        return $this;
    }

    /**
     * Mark the message as a warning and render it in warning styling.
     */
    public function warning(): self
    {
        $this->level = 'warning';

        return $this;
    }

    /**
     * Mark the message as an error and render it in error styling.
     */
    public function error(): self
    {
        $this->level = 'error';

        return $this;
    }

    /**
     * Mark the message as informational and render it in info styling.
     */
    public function info(): self
    {
        $this->level = 'info';

        return $this;
    }

    /**
     * Render the message in bold text.
     */
    public function bold(): self
    {
        $this->bold = true;

        return $this;
    }

    /**
     * Render the message with underline decoration.
     */
    public function underline(): self
    {
        $this->underline = true;

        return $this;
    }

    /**
     * Render the message with strike-through decoration.
     */
    public function strike(): self
    {
        $this->strike = true;

        return $this;
    }

    /**
     * Prefix the message with an icon matching its semantic level.
     */
    public function icon(): self
    {
        $this->icon = true;

        return $this;
    }

    /**
     * Render the message immediately if it has not already been sent.
     */
    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        $this->output->writeRaw($this->render());
        $this->sent = true;
    }

    /**
     * Render the message automatically when the fluent builder goes out of scope.
     */
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
