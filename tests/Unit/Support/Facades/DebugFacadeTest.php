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
        self::assertSame($this->logPath, Debug::logPath());
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

    public function testDebugCanResetConfiguredStateAndExposeDefaultLogPath(): void
    {
        Debug::setLogPath($this->logPath);
        Debug::clearLogPath();

        self::assertStringEndsWith('/build/debug.log', str_replace('\\', '/', Debug::logPath()));
    }

    public function testWriteCreatesNestedDirectoriesAndPreservesStringPayloads(): void
    {
        $path = sys_get_temp_dir() . '/myxa-debug-' . uniqid('', true) . '/nested/debug.log';
        Debug::setLogPath($path);

        Debug::write('plain message');

        $contents = file_get_contents($path);

        self::assertIsString($contents);
        self::assertStringContainsString('plain message', $contents);
        self::assertDirectoryExists(dirname($path));

        unlink($path);
        rmdir(dirname($path));
        rmdir(dirname(dirname($path)));
    }

    public function testDumpThrowsWhenCustomTerminatorReturnsUnexpectedly(): void
    {
        $stream = fopen('php://temp', 'w+');
        Debug::setOutput($stream);
        Debug::setTerminator(static function (int $code): void {
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Debug terminator returned unexpectedly.');

        Debug::dump('still here');
    }

    public function testDebugWriteCanResolveGlobalFunctionCallsite(): void
    {
        Debug::setLogPath($this->logPath);

        debug_facade_test_global_writer();

        $contents = file_get_contents($this->logPath);

        self::assertIsString($contents);
        self::assertStringContainsString('from-global', $contents);
        self::assertStringContainsString(basename(__FILE__), $contents);
    }

    public function testDebugReflectCandidateHandlesFunctionsMethodsAndInvalidFrames(): void
    {
        $method = new \ReflectionMethod(Debug::class, 'reflectCandidate');
        $method->setAccessible(true);

        $function = $method->invoke(null, ['function' => __NAMESPACE__ . '\\debug_facade_test_global_writer']);
        $classMethod = $method->invoke(null, [
            'class' => DebugFacadeTestReflectionTarget::class,
            'function' => 'call',
        ]);
        $invalid = $method->invoke(null, [
            'class' => 'MissingClass',
            'function' => 'missing',
        ]);

        self::assertSame(__FILE__, $function['file']);
        self::assertSame(__FILE__, $classMethod['file']);
        self::assertNull($invalid);
    }
}

final class DebugFacadeTestTermination extends \RuntimeException
{
    public function __construct(public int $exitCode)
    {
        parent::__construct('Debug terminated.');
    }
}

function debug_facade_test_global_writer(): void
{
    Debug::write('from-global');
}

final class DebugFacadeTestReflectionTarget
{
    public static function call(): void
    {
    }
}
