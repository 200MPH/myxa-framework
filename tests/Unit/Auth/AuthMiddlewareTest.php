<?php

declare(strict_types=1);

namespace Test\Unit\Auth;

use Myxa\Application;
use Myxa\Auth\AuthManager;
use Myxa\Auth\AuthServiceProvider;
use Myxa\Auth\BearerTokenResolverInterface;
use Myxa\Auth\Exceptions\AuthenticationException;
use Myxa\Auth\SessionUserResolverInterface;
use Myxa\Http\DefaultExceptionHandler;
use Myxa\Http\ExceptionHandlerInterface;
use Myxa\Http\Request;
use Myxa\Http\Response;
use Myxa\Middleware\AuthMiddleware;
use Myxa\Routing\RouteDefinition;
use Myxa\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(AuthMiddleware::class)]
#[CoversClass(AuthenticationException::class)]
final class AuthMiddlewareTest extends TestCase
{
    public function testWebMiddlewareThrowsAuthenticationExceptionForGuests(): void
    {
        $app = new Application();
        $app->register(AuthServiceProvider::class);
        $app->boot();

        $middleware = new AuthMiddleware($app->make(AuthManager::class));
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
        ]);
        $route = new RouteDefinition(['GET'], '/dashboard', static fn (): string => 'ok');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unauthenticated.');

        $middleware->handle($request, static fn (): string => 'ok', $route);
    }

    public function testUsingHelperTargetsNamedGuardInsideRouterMiddlewareStack(): void
    {
        $app = new Application();
        $app->instance(BearerTokenResolverInterface::class, new class implements BearerTokenResolverInterface
        {
            public function resolve(string $token, Request $request): mixed
            {
                return $token === 'api-token' ? ['id' => 7] : null;
            }
        });
        $app->register(AuthServiceProvider::class);
        $app->boot();
        $router = new Router($app);

        $router->get('/api/me', static fn (): string => 'ok')
            ->middleware(AuthMiddleware::using('api'));

        $guestRequest = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/me',
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $guestResponse = $this->dispatchWithExceptionHandler($router, $guestRequest, new DefaultExceptionHandler());
        $authenticatedResponse = $router->dispatch(new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/me',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer api-token',
        ]));

        self::assertInstanceOf(Response::class, $guestResponse);
        self::assertSame(401, $guestResponse->statusCode());
        self::assertSame('application/json; charset=UTF-8', $guestResponse->header('Content-Type'));
        self::assertSame('Bearer', $guestResponse->header('WWW-Authenticate'));
        self::assertSame(
            '{"error":{"type":"unauthenticated","message":"Unauthenticated.","status":401}}',
            $guestResponse->content(),
        );
        self::assertSame('ok', $authenticatedResponse);
    }

    public function testMiddlewareAllowsAuthenticatedRequestsThroughConfiguredGuard(): void
    {
        $app = new Application();
        $app->instance(SessionUserResolverInterface::class, new class implements SessionUserResolverInterface
        {
            public function resolve(string $sessionId, Request $request): mixed
            {
                return ['id' => 1, 'session' => $sessionId];
            }
        });
        $app->register(AuthServiceProvider::class);
        $app->boot();

        $middleware = new AuthMiddleware($app->make(AuthManager::class), 'web');
        $request = new Request(
            cookies: ['session' => 'sess-123'],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard'],
        );
        $route = new RouteDefinition(['GET'], '/dashboard', static fn (): string => 'ok');

        self::assertSame('ok', $middleware->handle($request, static fn (): string => 'ok', $route));
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
