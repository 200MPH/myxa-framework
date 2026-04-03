<?php

declare(strict_types=1);

namespace Test\Unit\Logging;

use Myxa\Application;
use Myxa\Logging\FileLogger;
use Myxa\Logging\LogLevel;
use Myxa\Logging\LoggerInterface;
use Myxa\Logging\LoggingServiceProvider;
use Myxa\Logging\NullLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileLogger::class)]
#[CoversClass(LogLevel::class)]
#[CoversClass(LoggingServiceProvider::class)]
#[CoversClass(NullLogger::class)]
final class LoggingTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/myxa-logs-' . bin2hex(random_bytes(6)) . '/app.log';
    }

    protected function tearDown(): void
    {
        $directory = dirname($this->path);
        if (is_file($this->path)) {
            @unlink($this->path);
        }

        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }

    public function testFileLoggerWritesStructuredLines(): void
    {
        $logger = new FileLogger($this->path);

        $logger->log(LogLevel::Error, 'Something failed.', ['status' => 500]);

        $contents = file_get_contents($this->path);

        self::assertIsString($contents);
        self::assertStringContainsString('ERROR Something failed.', $contents);
        self::assertStringContainsString('"status":500', $contents);
    }

    public function testLoggingServiceProviderRegistersDefaultLoggerBinding(): void
    {
        $app = new Application();
        $app->register(LoggingServiceProvider::class);
        $app->boot();

        self::assertInstanceOf(LoggerInterface::class, $app->make(LoggerInterface::class));
        self::assertInstanceOf(NullLogger::class, $app->make(LoggerInterface::class));
        self::assertSame($app->make(LoggerInterface::class), $app->make('logger'));
    }
}
