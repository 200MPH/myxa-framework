<?php

declare(strict_types=1);

namespace Test\Unit\Middleware;

use Myxa\Application;
use Myxa\Http\DefaultExceptionHandler;
use Myxa\Http\ExceptionHandlerInterface;
use Myxa\Http\Request;
use Myxa\Http\Response;
use Myxa\Middleware\RateLimitMiddleware;
use Myxa\RateLimit\FileRateLimiterStore;
use Myxa\RateLimit\RateLimiter;
use Myxa\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(RateLimitMiddleware::class)]
final class RateLimitMiddlewareTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/myxa-rate-limit-middleware-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testMiddlewareAllowsRequestsUntilLimitAndAddsHeadersToResponses(): void
    {
        $app = new Application();
        $app->instance(RateLimiter::class, new RateLimiter(new FileRateLimiterStore($this->directory)));
        $router = new Router($app);

        $router->get('/health', static fn (): Response => (new Response())->json(['ok' => true]))
            ->middleware(RateLimitMiddleware::using(2, 60, 'health'));

        $first = $router->dispatch(new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/health',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_ACCEPT' => 'application/json',
        ]));
        $second = $router->dispatch(new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/health',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_ACCEPT' => 'application/json',
        ]));

        self::assertInstanceOf(Response::class, $first);
        self::assertSame('2', $first->header('X-RateLimit-Limit'));
        self::assertSame('1', $first->header('X-RateLimit-Remaining'));
        self::assertInstanceOf(Response::class, $second);
        self::assertSame('0', $second->header('X-RateLimit-Remaining'));
    }

    public function testMiddlewareThrowsAndExceptionMiddlewareRendersTooManyRequestsResponse(): void
    {
        $app = new Application();
        $app->instance(RateLimiter::class, new RateLimiter(new FileRateLimiterStore($this->directory)));
        $router = new Router($app);

        $router->get('/api/posts', static fn (): Response => (new Response())->json(['ok' => true]))
            ->middleware(RateLimitMiddleware::using(1, 60, 'posts'));

        $first = $router->dispatch(new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/posts',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_ACCEPT' => 'application/json',
        ]));
        $secondRequest = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/posts',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $second = $this->dispatchWithExceptionHandler($router, $secondRequest, new DefaultExceptionHandler());

        self::assertInstanceOf(Response::class, $first);
        self::assertSame(200, $first->statusCode());
        self::assertInstanceOf(Response::class, $second);
        self::assertSame(429, $second->statusCode());
        self::assertSame('1', $second->header('X-RateLimit-Limit'));
        self::assertSame('0', $second->header('X-RateLimit-Remaining'));
        self::assertNotNull($second->header('Retry-After'));
        self::assertSame(
            '{"error":{"type":"too_many_requests","message":"Too Many Requests.","status":429}}',
            $second->content(),
        );
    }

    private function dispatchWithExceptionHandler(
        Router $router,
        Request $request,
        ExceptionHandlerInterface $handler,
    ): mixed {
        try {
            return $router->dispatch($request);
        } catch (Throwable $exception) {
            $handler->report($exception);

            return $handler->render($exception, $request);
        }
    }
}
