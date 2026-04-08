<?php

declare(strict_types=1);

namespace Myxa\Logging;

interface LoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(LogLevel $level, string $message, array $context = []): void;
}
