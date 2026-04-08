<?php

declare(strict_types=1);

namespace Myxa\Logging;

final class NullLogger implements LoggerInterface
{
    public function log(LogLevel $level, string $message, array $context = []): void
    {
    }
}
