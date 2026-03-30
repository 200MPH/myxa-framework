<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;

/**
 * Small static debug facade for quick dump-and-die and file logging workflows.
 */
final class Debug
{
    /**
     * @var resource|null
     */
    private static mixed $output = null;

    private static ?string $logPath = null;

    /**
     * @var callable(int): never|void|null
     */
    private static $terminator = null;

    /**
     * @param resource $stream
     */
    public static function setOutput(mixed $stream): void
    {
        self::$output = $stream;
    }

    public static function clearOutput(): void
    {
        self::$output = null;
    }

    public static function setLogPath(string $path): void
    {
        self::$logPath = $path;
    }

    public static function clearLogPath(): void
    {
        self::$logPath = null;
    }

    /**
     * @param callable(int): never|void $terminator
     */
    public static function setTerminator(callable $terminator): void
    {
        self::$terminator = $terminator;
    }

    public static function clearTerminator(): void
    {
        self::$terminator = null;
    }

    /**
     * Render debug information and terminate the current program.
     */
    public static function dump(mixed $data = null): never
    {
        self::writeToOutput(self::formatPayload($data));

        $terminator = self::$terminator ?? static function (int $code): never {
            exit($code);
        };

        $terminator(1);

        throw new RuntimeException('Debug terminator returned unexpectedly.');
    }

    /**
     * Append debug information to the configured debug log file.
     */
    public static function write(mixed $data = null): void
    {
        $path = self::logPath();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create debug log directory [%s].', $directory));
        }

        $bytes = file_put_contents($path, self::formatPayload($data) . PHP_EOL, \FILE_APPEND);
        if ($bytes === false) {
            throw new RuntimeException(sprintf('Unable to write debug output to [%s].', $path));
        }
    }

    /**
     * Return the configured debug log path.
     */
    public static function logPath(): string
    {
        return self::$logPath ??= getcwd() . '/build/debug.log';
    }

    /**
     * Build a formatted debug payload with caller file and line information.
     */
    private static function formatPayload(mixed $data): string
    {
        $caller = self::callerFrame();
        $header = sprintf(
            '[DEBUG] %s:%d @ %s memory=%s peak=%s',
            $caller['file'],
            $caller['line'],
            date('Y-m-d H:i:s'),
            self::formatBytes(memory_get_usage(true)),
            self::formatBytes(memory_get_peak_usage(true)),
        );

        return $header . PHP_EOL . self::stringify($data);
    }

    /**
     * Resolve the file and line of the debug caller.
     *
     * @return array{file: string, line: int}
     */
    private static function callerFrame(): array
    {
        $trace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
        $fallback = [];

        foreach ($trace as $candidate) {
            if (($candidate['class'] ?? null) === self::class) {
                continue;
            }

            if ($fallback === []) {
                $fallback = $candidate;
            }

            $file = $candidate['file'] ?? null;
            if (is_string($file) && !str_contains(str_replace('\\', '/', $file), '/vendor/')) {
                return [
                    'file' => $file,
                    'line' => is_int($candidate['line'] ?? null) ? $candidate['line'] : 0,
                ];
            }

            $reflected = self::reflectCandidate($candidate);
            if ($reflected !== null) {
                return $reflected;
            }
        }

        return [
            'file' => is_string($fallback['file'] ?? null) ? $fallback['file'] : 'unknown',
            'line' => is_int($fallback['line'] ?? null) ? $fallback['line'] : 0,
        ];
    }

    /**
     * Convert debug data into a readable string representation.
     */
    private static function stringify(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }

        return rtrim(print_r($data, true));
    }

    /**
     * Write a formatted debug payload to the configured output stream.
     */
    private static function writeToOutput(string $payload): void
    {
        $stream = self::$output;

        if (!is_resource($stream)) {
            $stream = fopen('php://stdout', 'wb');
        }

        fwrite($stream, $payload . PHP_EOL);
    }

    /**
     * Try to resolve a useful file and line from a trace frame via reflection.
     *
     * @param array<string, mixed> $frame
     *
     * @return array{file: string, line: int}|null
     */
    private static function reflectCandidate(array $frame): ?array
    {
        try {
            if (is_string($frame['class'] ?? null) && is_string($frame['function'] ?? null)) {
                $reflection = new ReflectionMethod($frame['class'], $frame['function']);
                $file = $reflection->getFileName();

                return $file !== false ? ['file' => $file, 'line' => $reflection->getStartLine()] : null;
            }

            if (is_string($frame['function'] ?? null)) {
                $reflection = new ReflectionFunction($frame['function']);
                $file = $reflection->getFileName();

                return $file !== false ? ['file' => $file, 'line' => $reflection->getStartLine()] : null;
            }
        } catch (\ReflectionException) {
            return null;
        }

        return null;
    }

    /**
     * Format bytes in a compact human-readable unit.
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%.0f %s' : '%.2f %s', $value, $units[$unit]);
    }
}
