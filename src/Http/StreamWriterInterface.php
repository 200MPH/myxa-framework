<?php

declare(strict_types=1);

namespace Myxa\Http;

interface StreamWriterInterface
{
    /**
     * Write a chunk to the active response stream and push it to the client.
     */
    public function write(string $chunk): void;

    /**
     * Flush any buffered stream output to the client.
     */
    public function flush(): void;
}
