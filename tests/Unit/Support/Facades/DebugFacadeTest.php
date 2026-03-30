<?php

declare(strict_types=1);

namespace Test\Unit\Support\Facades;

use Myxa\Support\Facades\Debug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Debug::class)]
final class DebugFacadeTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/myxa-debug-' . uniqid('', true) . '.log';

        Debug::clearOutput();
        Debug::clearLogPath();
        Debug::clearTerminator();
    }

    protected function tearDown(): void
    {
        Debug::clearOutput();
        Debug::clearLogPath();
        Debug::clearTerminator();

        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }
    }

    public function testWriteAppendsFormattedDebugPayloadToFile(): void
    {
        Debug::setLogPath($this->logPath);

        Debug::write(['framework' => 'myxa']);

        $contents = file_get_contents($this->logPath);

        self::assertIsString($contents);
        self::assertMatchesRegularExpression(
            '/\[DEBUG\] '
            . preg_quote(str_replace('\\', '/', __FILE__), '/')
            . ':\d+ @ .+ memory=\d+(?:\.\d{2})? (?:B|KB|MB|GB|TB) peak=\d+(?:\.\d{2})? (?:B|KB|MB|GB|TB)/',
            str_replace('\\', '/', $contents),
        );
        self::assertStringContainsString('[framework] => myxa', $contents);
    }

    public function testDumpWritesFormattedPayloadAndTerminates(): void
    {
        $stream = fopen('php://temp', 'w+');
        Debug::setOutput($stream);
        Debug::setTerminator(static function (int $code): never {
            throw new DebugFacadeTestTermination($code);
        });

        try {
            Debug::dump('debug me');
            self::fail('Expected DebugFacadeTestTermination to be thrown.');
        } catch (DebugFacadeTestTermination $exception) {
            self::assertSame(1, $exception->exitCode);
        }

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertMatchesRegularExpression(
            '/\[DEBUG\] '
            . preg_quote(str_replace('\\', '/', __FILE__), '/')
            . ':\d+ @ .+ memory=\d+(?:\.\d{2})? (?:B|KB|MB|GB|TB) peak=\d+(?:\.\d{2})? (?:B|KB|MB|GB|TB)/',
            str_replace('\\', '/', $contents),
        );
        self::assertStringContainsString('debug me', $contents);
    }
}

final class DebugFacadeTestTermination extends \RuntimeException
{
    public function __construct(public int $exitCode)
    {
        parent::__construct('Debug terminated.');
    }
}
