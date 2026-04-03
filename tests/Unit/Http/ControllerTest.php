<?php

declare(strict_types=1);

namespace Test\Unit\Http;

use Myxa\Application;
use Myxa\Container\Container;
use Myxa\Http\Controller;
use Myxa\Http\Request;
use Myxa\Routing\RouteDefinition;
use Myxa\Routing\Exceptions\MethodNotAllowedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(Container::class)]
#[CoversClass(Controller::class)]
#[CoversClass(Request::class)]
#[CoversClass(MethodNotAllowedException::class)]
#[CoversClass(RouteDefinition::class)]
final class ControllerTest extends TestCase
{
    public function testControllerDispatchesToMethodSpecificActionWithRouteParameters(): void
    {
        $app = new Application();
        $app->instance(ControllerTestDependency::class, new ControllerTestDependency('dep'));
        $controller = $app->make(ControllerTestVerbController::class);
        $route = new RouteDefinition(['GET'], '/users/{id}', ControllerTestVerbController::class);
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/users/42',
        ]);

        self::assertSame('get:42:dep', $controller($request, $route));
    }

    public function testControllerCanOverrideMethodMappingForSaveStyleActions(): void
    {
        $app = new Application();
        $controller = $app->make(ControllerTestSaveController::class);
        $route = new RouteDefinition(['PUT'], '/users/{id}', ControllerTestSaveController::class);
        $request = new Request(server: [
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI' => '/users/7',
        ]);

        self::assertSame('save:7', $controller($request, $route));
    }

    public function testControllerReportsMethodNotAllowedWhenNoActionExistsForRequestMethod(): void
    {
        $app = new Application();
        $controller = $app->make(ControllerTestVerbController::class);
        $route = new RouteDefinition(['POST'], '/users', ControllerTestVerbController::class);
        $request = new Request(server: [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/users',
        ]);

        $this->expectException(MethodNotAllowedException::class);
        $this->expectExceptionMessage(
            'Method [POST] is not allowed for route [/users]. Allowed methods: DELETE, GET.',
        );

        $controller($request, $route);
    }
}

final readonly class ControllerTestDependency
{
    public function __construct(public string $name)
    {
    }
}

final class ControllerTestVerbController extends Controller
{
    public function get(string $id, ControllerTestDependency $dependency): string
    {
        return sprintf('get:%s:%s', $id, $dependency->name);
    }

    public function delete(): string
    {
        return 'delete';
    }
}

final class ControllerTestSaveController extends Controller
{
    protected function methodActionMap(): array
    {
        return [
            'POST' => 'save',
            'PUT' => 'save',
            'PATCH' => 'save',
        ];
    }

    public function save(string $id): string
    {
        return 'save:' . $id;
    }
}
