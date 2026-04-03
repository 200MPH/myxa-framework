<?php

declare(strict_types=1);

namespace Myxa\Logging;

use RuntimeException;

final class FileLogger implements LoggerInterface
{
    public function __construct(private readonly string $path)
    {
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create log directory [%s].', $directory));
        }

        $line = sprintf(
            "[%s] %s %s%s\n",
            date(DATE_ATOM),
            strtoupper($level->value),
            $message,
            $context === [] ? '' : ' ' . $this->encodeContext($context),
        );

        if (@file_put_contents($this->path, $line, \FILE_APPEND | \LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write log file [%s].', $this->path));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function encodeContext(array $context): string
    {
        $encoded = json_encode($this->normalizeContext($context), \JSON_THROW_ON_ERROR);

        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $normalized[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];

                continue;
            }

            if ($value instanceof \Stringable) {
                $normalized[$key] = (string) $value;

                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = $this->normalizeContext($value);

                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
