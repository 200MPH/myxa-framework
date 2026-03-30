<?php

declare(strict_types=1);

namespace Test\Unit\Routing;

use Myxa\Application;
use Myxa\Http\Request as HttpRequest;
use Myxa\Middleware\MiddlewareInterface;
use Myxa\Routing\RouteDefinition;
use Myxa\Routing\Router;
use Myxa\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(HttpRequest::class)]
#[CoversClass(RouteDefinition::class)]
#[CoversClass(RouteFacade::class)]
#[CoversClass(Router::class)]
final class RouteFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        RouteFacade::clearRouter();
    }

    public function testFacadeDelegatesToCurrentRouterInstance(): void
    {
        $router = new Router(new Application());
        RouteFacade::setRouter($router);

        RouteFacade::group('/api', function (): void {
            RouteFacade::get('/users/{id}', static fn (string $id): string => 'user:' . $id)
                ->middleware(RouteFacadeTestMiddleware::class);
        });

        self::assertSame($router, RouteFacade::getRouter());
        self::assertTrue(RouteFacade::has('GET', '/api/users/12'));
        self::assertSame('/api/users/{id}', RouteFacade::find('GET', '/api/users/12')->path());
        self::assertSame(
            'before|user:12|after',
            RouteFacade::dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/users/12',
            ])),
        );
    }

    public function testFacadeMagicCallStaticForwardsToUnderlyingRouter(): void
    {
        $router = new Router(new Application());
        $router->get('/magic', static fn (): string => 'magic');

        RouteFacade::setRouter($router);

        self::assertTrue(RouteFacade::__callStatic('has', ['GET', '/magic']));
    }
}

final class RouteFacadeTestMiddleware implements MiddlewareInterface
{
    public function handle(HttpRequest $request, \Closure $next, RouteDefinition $route): string
    {
        return 'before|' . $next() . '|after';
    }
}
