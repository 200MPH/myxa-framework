<?php

declare(strict_types=1);

namespace Test\Unit\Middleware;

use Myxa\Http\DefaultExceptionHandler;
use Myxa\Http\Request;
use Myxa\Http\Response;
use Myxa\Middleware\HandleExceptionsMiddleware;
use Myxa\Routing\RouteDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HandleExceptionsMiddleware::class)]
final class HandleExceptionsMiddlewareTest extends TestCase
{
    public function testMiddlewareConvertsThrownExceptionsIntoResponses(): void
    {
        $middleware = new HandleExceptionsMiddleware(new DefaultExceptionHandler());
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/fail',
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $route = new RouteDefinition(['GET'], '/api/fail', static fn (): never => throw new \RuntimeException('Boom'));

        $response = $middleware->handle($request, static fn (): never => throw new \RuntimeException('Boom'), $route);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(500, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
    }
}
