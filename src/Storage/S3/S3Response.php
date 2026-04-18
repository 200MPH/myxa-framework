<?php

declare(strict_types=1);

namespace Myxa\Storage\S3;

final readonly class S3Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $statusCode,
        array $headers = [],
        private string $body = '',
    ) {
        $normalizedHeaders = [];

        foreach ($headers as $name => $value) {
            $normalizedHeaders[strtolower(trim($name))] = trim($value);
        }

        $this->headers = $normalizedHeaders;
    }

    /** @var array<string, string> */
    private array $headers;

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower(trim($name))] ?? null;
    }
}
