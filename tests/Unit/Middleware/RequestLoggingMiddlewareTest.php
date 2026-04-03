<?php

declare(strict_types=1);

namespace Test\Unit\Middleware;

use Myxa\Http\Request;
use Myxa\Http\Response;
use Myxa\Logging\LogLevel;
use Myxa\Logging\LoggerInterface;
use Myxa\Middleware\RequestLoggingMiddleware;
use Myxa\Routing\RouteDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestLoggingMiddleware::class)]
final class RequestLoggingMiddlewareTest extends TestCase
{
    public function testMiddlewareLogsCompletedRequests(): void
    {
        $logger = new RequestLoggingMiddlewareTestLogger();
        $middleware = new RequestLoggingMiddleware($logger);
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/health',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $route = new RouteDefinition(['GET'], '/health', static fn (): Response => (new Response())->text('ok'));

        $response = $middleware->handle(
            $request,
            static fn (): Response => (new Response())->text('ok'),
            $route,
        );

        self::assertSame('ok', $response->content());
        self::assertCount(1, $logger->entries);
        self::assertSame(LogLevel::Info->value, $logger->entries[0]['level']);
        self::assertSame('HTTP request completed.', $logger->entries[0]['message']);
        self::assertSame('/health', $logger->entries[0]['context']['path']);
        self::assertSame(200, $logger->entries[0]['context']['status']);
    }
}

final class RequestLoggingMiddlewareTestLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $this->entries[] = [
            'level' => $level->value,
            'message' => $message,
            'context' => $context,
        ];
    }
}
