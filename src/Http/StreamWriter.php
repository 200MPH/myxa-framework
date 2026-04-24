<?php

declare(strict_types=1);

namespace Myxa\Http;

final class StreamWriter implements StreamWriterInterface
{
    /**
     * Write a chunk to the active response stream and push it to the client.
     */
    public function write(string $chunk): void
    {
        echo $chunk;

        $this->flush();
    }

    /**
     * Flush any buffered stream output to the client.
     */
    public function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
