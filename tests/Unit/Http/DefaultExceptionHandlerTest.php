<?php

declare(strict_types=1);

namespace Test\Unit\Http;

use Myxa\Auth\AuthenticationException;
use Myxa\Database\Model\ModelNotFoundException;
use Myxa\Http\DefaultExceptionHandler;
use Myxa\Http\ExceptionHttpMapper;
use Myxa\Http\ExceptionHandlerInterface;
use Myxa\Http\ExceptionHandlerServiceProvider;
use Myxa\Http\Request;
use Myxa\Http\Response;
use Myxa\RateLimit\RateLimitResult;
use Myxa\RateLimit\TooManyRequestsException;
use Myxa\Routing\RouteNotFoundException;
use Myxa\Routing\MethodNotAllowedException;
use Myxa\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultExceptionHandler::class)]
#[CoversClass(ExceptionHttpMapper::class)]
#[CoversClass(ExceptionHandlerServiceProvider::class)]
final class DefaultExceptionHandlerTest extends TestCase
{
    public function testHandlerRendersJsonErrorsForApiRequests(): void
    {
        $handler = new DefaultExceptionHandler();
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/users/99',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $handler->render(
            ModelNotFoundException::forKey('App\\User', 99),
            $request,
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(404, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame(
            '{"error":{"type":"model_not_found","message":"No record was found for model App\\\\User with key \\"99\\".","status":404}}',
            $response->content(),
        );
    }

    public function testHandlerSetsAllowHeaderForMethodNotAllowedExceptions(): void
    {
        $handler = new DefaultExceptionHandler();
        $request = new Request(server: [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/posts',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $handler->render(
            new MethodNotAllowedException('POST', '/api/posts', ['GET', 'PUT']),
            $request,
        );

        self::assertSame(405, $response->statusCode());
        self::assertSame('GET, PUT', $response->header('Allow'));
    }

    public function testHandlerHidesServerErrorDetailsByDefault(): void
    {
        $handler = new DefaultExceptionHandler();
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/posts',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $handler->render(new \RuntimeException('Sensitive details'), $request);

        self::assertSame(500, $response->statusCode());
        self::assertSame(
            '{"error":{"type":"runtime","message":"Server Error","status":500}}',
            $response->content(),
        );
    }

    public function testHandlerRedirectsAuthenticationExceptionsForBrowserRequests(): void
    {
        $handler = new DefaultExceptionHandler();
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
        ]);

        $response = $handler->render(new AuthenticationException('web', '/login'), $request);

        self::assertSame(302, $response->statusCode());
        self::assertSame('/login', $response->header('Location'));
    }

    public function testHandlerRendersJsonAuthenticationErrorsForApiRequests(): void
    {
        $handler = new DefaultExceptionHandler();
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/me',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $handler->render(new AuthenticationException('api'), $request);

        self::assertSame(401, $response->statusCode());
        self::assertSame('Bearer', $response->header('WWW-Authenticate'));
        self::assertSame(
            '{"error":{"type":"unauthenticated","message":"Unauthenticated.","status":401}}',
            $response->content(),
        );
    }

    public function testExceptionHttpMapperResolvesStatuses(): void
    {
        self::assertSame(404, ExceptionHttpMapper::statusCodeFor(new RouteNotFoundException('GET', '/missing')));
        self::assertSame(405, ExceptionHttpMapper::statusCodeFor(new MethodNotAllowedException('POST', '/posts', ['GET'])));
        self::assertSame(401, ExceptionHttpMapper::statusCodeFor(new AuthenticationException()));
        self::assertSame(429, ExceptionHttpMapper::statusCodeFor(new TooManyRequestsException(
            new RateLimitResult('api|127.0.0.1|/posts', 2, 1, 0, 60, 9999999999, true),
        )));
        self::assertSame(500, ExceptionHttpMapper::statusCodeFor(new \RuntimeException('Boom')));
    }

    public function testHandlerAddsRateLimitHeadersForTooManyRequestsExceptions(): void
    {
        $handler = new DefaultExceptionHandler();
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/posts',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $handler->render(new TooManyRequestsException(
            new RateLimitResult('api|127.0.0.1|/posts', 2, 1, 0, 42, 9999999999, true),
        ), $request);

        self::assertSame(429, $response->statusCode());
        self::assertSame('42', $response->header('Retry-After'));
        self::assertSame('1', $response->header('X-RateLimit-Limit'));
        self::assertSame('0', $response->header('X-RateLimit-Remaining'));
        self::assertSame('9999999999', $response->header('X-RateLimit-Reset'));
    }

    public function testServiceProviderRegistersDefaultHandlerBinding(): void
    {
        $app = new Application();
        $app->register(ExceptionHandlerServiceProvider::class);
        $app->boot();

        self::assertInstanceOf(ExceptionHandlerInterface::class, $app->make(ExceptionHandlerInterface::class));
        self::assertInstanceOf(DefaultExceptionHandler::class, $app->make(ExceptionHandlerInterface::class));
    }
}
