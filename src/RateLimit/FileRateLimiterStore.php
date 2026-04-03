<?php

declare(strict_types=1);

namespace Myxa\RateLimit;

use RuntimeException;

/**
 * Simple file-backed rate limit store that persists counters across requests.
 */
final class FileRateLimiterStore implements RateLimiterStoreInterface
{
    public function __construct(private readonly string $directory)
    {
    }

    public function increment(string $key, int $decaySeconds, int $now): RateLimitCounter
    {
        $path = $this->pathFor($key);
        $this->ensureDirectoryExists();

        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open rate limit store file [%s].', $path));
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new RuntimeException(sprintf('Unable to lock rate limit store file [%s].', $path));
            }

            $state = $this->readState($handle);
            $attempts = $state['attempts'] ?? 0;
            $expiresAt = $state['expires_at'] ?? 0;

            if ($expiresAt <= $now) {
                $attempts = 0;
                $expiresAt = $now + $decaySeconds;
            }

            $attempts++;

            $this->writeState($handle, [
                'attempts' => $attempts,
                'expires_at' => $expiresAt,
            ]);

            flock($handle, \LOCK_UN);

            return new RateLimitCounter($attempts, $expiresAt);
        } finally {
            fclose($handle);
        }
    }

    public function clear(string $key): void
    {
        $path = $this->pathFor($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function ensureDirectoryExists(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!@mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create rate limit directory [%s].', $this->directory));
        }
    }

    /**
     * @return array{attempts?: int, expires_at?: int}
     */
    private function readState(mixed $handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);

        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        return [
            'attempts' => isset($decoded['attempts']) && is_int($decoded['attempts']) ? $decoded['attempts'] : null,
            'expires_at' => isset($decoded['expires_at']) && is_int($decoded['expires_at']) ? $decoded['expires_at'] : null,
        ];
    }

    /**
     * @param array{attempts: int, expires_at: int} $state
     */
    private function writeState(mixed $handle, array $state): void
    {
        $encoded = json_encode($state, \JSON_THROW_ON_ERROR);
        if (!is_string($encoded)) {
            throw new RuntimeException('Failed to encode rate limit state.');
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded);
        fflush($handle);
    }

    private function pathFor(string $key): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    }
}
