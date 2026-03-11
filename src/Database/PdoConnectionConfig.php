<?php

declare(strict_types=1);

namespace Myxa\Database;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Immutable PDO connection configuration.
 */
final readonly class PdoConnectionConfig
{
    /**
     * @param array<int, mixed> $options PDO options.
     * @param array<string, scalar|null> $dsnExtras Additional DSN segments (for example: unix_socket).
     *
     * @throws InvalidArgumentException When required DSN parts are missing.
     */
    public function __construct(
        private string $engine,
        private string $database,
        private string $host,
        private ?int $port = null,
        private ?string $charset = null,
        private ?string $username = null,
        #[SensitiveParameter]
        private ?string $password = null,
        private array $options = [],
        private array $dsnExtras = [],
    ) {
        if (trim($this->engine) === '' || trim($this->database) === '' || trim($this->host) === '') {
            throw new InvalidArgumentException('Engine, database and host are required.');
        }
    }

    /**
     * Build config by parsing a DSN string.
     * This is a compatibility helper when DSN is already available as one string.
     */
    public static function fromDsn(
        string $dsn,
        ?string $username = null,
        #[SensitiveParameter]
        ?string $password = null,
        array $options = [],
    ): self {
        $dsn = trim($dsn);

        if ($dsn === '') {
            throw new InvalidArgumentException('DSN cannot be empty.');
        }

        $parts = explode(':', $dsn, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            throw new InvalidArgumentException('DSN must be in format "engine:key=value;...".');
        }

        [$engine, $segments] = $parts;
        $parsed = [];

        foreach (explode(';', $segments) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $pair = explode('=', $segment, 2);
            if (count($pair) !== 2 || trim($pair[0]) === '') {
                continue;
            }

            $parsed[trim($pair[0])] = trim($pair[1]);
        }

        $database = $parsed['dbname'] ?? '';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) && $parsed['port'] !== '' ? (int) $parsed['port'] : null;
        $charset = $parsed['charset'] ?? null;

        unset($parsed['dbname'], $parsed['host'], $parsed['port'], $parsed['charset']);

        return new self(
            engine: $engine,
            database: $database,
            host: $host,
            port: $port,
            charset: $charset,
            username: $username,
            password: $password,
            options: $options,
            dsnExtras: $parsed,
        );
    }

    /**
     * Full PDO DSN string.
     */
    public function getDsn(): string
    {
        $dsn = sprintf('%s:dbname=%s;host=%s', $this->engine, $this->database, $this->host);

        if ($this->port !== null) {
            $dsn .= sprintf(';port=%d', $this->port);
        }

        if ($this->charset !== null && trim($this->charset) !== '') {
            $dsn .= sprintf(';charset=%s', $this->charset);
        }

        foreach ($this->dsnExtras as $key => $value) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $dsn .= sprintf(';%s=%s', $key, (string) $value);
        }

        return $dsn;
    }

    /**
     * Database username.
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * Database password.
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * PDO driver options.
     *
     * @return array<int, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
