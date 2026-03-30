<?php

declare(strict_types=1);

namespace Test\Unit\Routing;

use Myxa\Application;
use Myxa\Http\Request as HttpRequest;
use Myxa\Routing\MethodNotAllowedException;
use Myxa\Routing\RouteDefinition;
use Myxa\Routing\RouteNotFoundException;
use Myxa\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(HttpRequest::class)]
#[CoversClass(MethodNotAllowedException::class)]
#[CoversClass(RouteDefinition::class)]
#[CoversClass(RouteNotFoundException::class)]
#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    public function testRouterRegistersRoutesForMethodsAndNestedGroups(): void
    {
        $router = new Router(new Application());

        $router->get('/', static fn (): string => 'home');
        $router->group('/api', function (Router $router): void {
            $router->post('/users', static fn (): string => 'users');
            $router->group('/v1', function (Router $router): void {
                $router->match(['put', 'patch'], '/profile/', static fn (): string => 'profile');
            });
        });
        $router->any('/ping', static fn (): string => 'pong');

        $routes = $router->routes();

        self::assertCount(4, $routes);
        self::assertSame('/', $routes[0]->path());
        self::assertSame(['GET'], $routes[0]->methods());
        self::assertSame('/api/users', $routes[1]->path());
        self::assertSame(['POST'], $routes[1]->methods());
        self::assertSame('/api/v1/profile', $routes[2]->path());
        self::assertSame(['PUT', 'PATCH'], $routes[2]->methods());
        self::assertSame('/ping', $routes[3]->path());
        self::assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], $routes[3]->methods());
    }

    public function testRouterDispatchesClosuresWithInjectedRequestAndDependencies(): void
    {
        $app = new Application();
        $router = new Router($app);
        $app->instance(RouterTestDependency::class, new RouterTestDependency('framework'));

        $router->group('/api', function (Router $router): void {
            $router->post('/users', static function (
                RouterTestDependency $dependency,
                HttpRequest $incomingRequest,
                RouteDefinition $matchedRoute,
            ): string {
                return sprintf(
                    '%s:%s:%s:%s',
                    $dependency->name,
                    $incomingRequest->method(),
                    $incomingRequest->path(),
                    implode('|', $matchedRoute->methods()),
                );
            });
        });

        $request = new HttpRequest(server: [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/users',
        ]);

        self::assertSame('framework:POST:/api/users:POST', $router->dispatch($request));
    }

    public function testRouterExtractsRouteParametersForClosuresAndControllers(): void
    {
        $app = new Application();
        $router = new Router($app);
        $app->instance(RouterTestDependency::class, new RouterTestDependency('params'));

        $router->get('/users/{id}', static function (string $id, HttpRequest $request): string {
            return sprintf('%s:%s', $id, $request->path());
        });

        $router->get('/posts/{postId}/comments/{commentId}', [RouterTestController::class, 'showComment']);

        self::assertSame(
            '42:/users/42',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/users/42',
            ])),
        );

        self::assertSame(
            'comment:params:99:7',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/posts/99/comments/7',
            ])),
        );
    }

    public function testRouterPrefersExactRoutesOverParameterizedRoutes(): void
    {
        $router = new Router(new Application());
        $router->get('/users/{id}', static fn (string $id): string => 'param:' . $id);
        $router->get('/users/create', static fn (): string => 'exact');

        self::assertSame(
            'exact',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/users/create',
            ])),
        );
    }

    public function testRouterDispatchesControllerStyleHandlers(): void
    {
        $app = new Application();
        $router = new Router($app);
        $app->instance(RouterTestDependency::class, new RouterTestDependency('controller'));

        $router->get('/array-handler', [RouterTestController::class, 'arrayHandler']);
        $router->put('/string-handler', RouterTestController::class . '::stringHandler');

        self::assertSame(
            'array:controller:/array-handler',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/array-handler',
            ])),
        );

        self::assertSame(
            'string:controller:PUT',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'PUT',
                'REQUEST_URI' => '/string-handler',
            ])),
        );
    }

    public function testRouterReportsMethodNotAllowedAndMissingRoutes(): void
    {
        $router = new Router(new Application());
        $router->get('/users', static fn (): string => 'users');
        $router->get('/posts/{id}', static fn (string $id): string => $id);

        try {
            $router->find('POST', '/users');
            self::fail('Expected MethodNotAllowedException to be thrown.');
        } catch (MethodNotAllowedException $exception) {
            self::assertSame(['GET'], $exception->allowedMethods());
            self::assertSame(
                'Method [POST] is not allowed for route [/users]. Allowed methods: GET.',
                $exception->getMessage(),
            );
        }

        try {
            $router->find('POST', '/posts/123');
            self::fail('Expected MethodNotAllowedException for parameterized route.');
        } catch (MethodNotAllowedException $exception) {
            self::assertSame(['GET'], $exception->allowedMethods());
        }

        self::assertFalse($router->has('POST', '/users'));
        self::assertTrue($router->has('GET', '/posts/123'));
        self::assertFalse($router->has('GET', '/missing'));

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('Route [GET /missing] was not found.');

        $router->find('GET', '/missing');
    }
}

final readonly class RouterTestDependency
{
    public function __construct(public string $name)
    {
    }
}

final readonly class RouterTestController
{
    public function __construct(private RouterTestDependency $dependency)
    {
    }

    public function arrayHandler(HttpRequest $request): string
    {
        return sprintf('array:%s:%s', $this->dependency->name, $request->path());
    }

    public function stringHandler(RouteDefinition $route): string
    {
        return sprintf('string:%s:%s', $this->dependency->name, implode('|', $route->methods()));
    }

    public function showComment(string $postId, string $commentId): string
    {
        return sprintf('comment:%s:%s:%s', $this->dependency->name, $postId, $commentId);
    }
}
