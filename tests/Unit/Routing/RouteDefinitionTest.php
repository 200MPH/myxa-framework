<?php

declare(strict_types=1);

namespace Test\Unit\Routing;

use InvalidArgumentException;
use Myxa\Routing\RouteDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteDefinition::class)]
final class RouteDefinitionTest extends TestCase
{
    public function testRouteDefinitionExposesMetadataAndMiddlewareStack(): void
    {
        $handler = static fn (): string => 'ok';
        $route = new RouteDefinition(['GET', 'POST'], '/users/{id}', $handler, ['auth']);

        $result = $route
            ->middleware('throttle', ['audit', 'csrf']);

        self::assertSame($route, $result);
        self::assertSame(['GET', 'POST'], $route->methods());
        self::assertSame('/users/{id}', $route->path());
        self::assertSame($handler, $route->handler());
        self::assertSame(['auth', 'throttle', 'audit', 'csrf'], $route->middlewares());
        self::assertTrue($route->allowsMethod('GET'));
        self::assertTrue($route->matchesPath('/users/15'));
        self::assertSame(['id' => '15'], $route->parametersForPath('/users/15'));
        self::assertSame([], (new RouteDefinition(['GET'], '/', $handler))->parametersForPath('/'));
        self::assertNull($route->parametersForPath('/posts/15'));
        self::assertFalse($route->matchesPath('/posts/15'));
    }

    public function testRouteDefinitionRejectsDuplicateParametersAndInvalidSegments(): void
    {
        try {
            new RouteDefinition(['GET'], '/users/{id}/{id}', static fn (): string => 'dup');
            self::fail('Expected duplicate parameter exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Route path [/users/{id}/{id}] contains duplicate parameter names.', $exception->getMessage());
        }

        $route = new RouteDefinition(['GET'], '/users-{id}', static fn (): string => 'bad');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route path [/users-{id}] contains an invalid parameter segment [users-{id}].');

        $route->parametersForPath('/users-15');
    }
}
