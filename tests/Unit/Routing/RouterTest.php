<?php

declare(strict_types=1);

namespace Test\Unit\Routing;

use Myxa\Application;
use Myxa\Http\Controller;
use Myxa\Http\Request as HttpRequest;
use Myxa\Middleware\MiddlewareInterface;
use Myxa\Routing\RouteDefinition;
use Myxa\Routing\Router;
use Myxa\Routing\Exceptions\MethodNotAllowedException;
use Myxa\Routing\Exceptions\RouteNotFoundException;
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

    public function testRouterDispatchesInvokableControllerClassHandlers(): void
    {
        $app = new Application();
        $router = new Router($app);

        $router->any('/users/{id}', RouterTestInvokableController::class);

        self::assertSame(
            'invokable:get:55',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/users/55',
            ])),
        );

        self::assertSame(
            'invokable:delete:55',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'DELETE',
                'REQUEST_URI' => '/users/55',
            ])),
        );
    }

    public function testRouterRunsRouteAndGroupMiddlewareAroundHandlers(): void
    {
        $app = new Application();
        $router = new Router($app);

        $router->group('/api', function (Router $router): void {
            $router->get('/users/{id}', static fn (string $id): string => 'handler:' . $id)
                ->middleware(
                    static function (string $id, \Closure $next): string {
                        return 'route-before:' . $id . '|' . $next() . '|route-after:' . $id;
                    },
                );
        }, static function (HttpRequest $request, \Closure $next): string {
            return 'group-before:' . $request->path() . '|' . $next() . '|group-after';
        });

        self::assertSame(
            'group-before:/api/users/42|route-before:42|handler:42|route-after:42|group-after',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/users/42',
            ])),
        );
    }

    public function testRouterSupportsClassMiddlewareAndShortCircuitResponses(): void
    {
        $app = new Application();
        $app->instance(RouterTestDependency::class, new RouterTestDependency('middleware'));
        $router = new Router($app);

        $router->get('/posts/{id}', static fn (string $id): string => 'handler:' . $id)
            ->middleware(RouterTestClassMiddleware::class);

        $router->get('/blocked', static fn (): string => 'handler')
            ->middleware(RouterTestBlockingMiddleware::class);

        self::assertSame(
            'middleware:8|handler:8|after',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/posts/8',
            ])),
        );

        self::assertSame(
            'blocked:/blocked',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/blocked',
            ])),
        );
    }

    public function testRouterSupportsMiddlewareOnlyGroups(): void
    {
        $router = new Router(new Application());

        $router->middleware(static function (\Closure $next): string {
            return 'outer|' . $next() . '|done';
        }, function (Router $router): void {
            $router->get('/health', static fn (): string => 'ok');
        });

        self::assertSame(
            'outer|ok|done',
            $router->dispatch(new HttpRequest(server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/health',
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

final class RouterTestInvokableController extends Controller
{
    public function get(string $id): string
    {
        return 'invokable:get:' . $id;
    }

    public function delete(string $id): string
    {
        return 'invokable:delete:' . $id;
    }
}

final readonly class RouterTestClassMiddleware implements MiddlewareInterface
{
    public function __construct(private RouterTestDependency $dependency)
    {
    }

    public function handle(HttpRequest $request, \Closure $next, RouteDefinition $route): string
    {
        $parameters = $route->parametersForPath($request->path()) ?? [];

        return sprintf(
            '%s:%s|%s|after',
            $this->dependency->name,
            $parameters['id'] ?? 'missing',
            $next(),
        );
    }
}

final class RouterTestBlockingMiddleware implements MiddlewareInterface
{
    public function handle(HttpRequest $request, \Closure $next, RouteDefinition $route): string
    {
        return 'blocked:' . $request->path();
    }
}
