<?php

declare(strict_types=1);

namespace Test\Unit\Routing;

use Myxa\Application;
use Myxa\Http\Request as HttpRequest;
use Myxa\Http\RequestServiceProvider;
use Myxa\Routing\RouteServiceProvider;
use Myxa\Routing\Router;
use Myxa\Support\Facades\Request as RequestFacade;
use Myxa\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(HttpRequest::class)]
#[CoversClass(RequestFacade::class)]
#[CoversClass(RequestServiceProvider::class)]
#[CoversClass(RouteFacade::class)]
#[CoversClass(RouteServiceProvider::class)]
#[CoversClass(Router::class)]
final class RouteServiceProviderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalGet;

    /** @var array<string, mixed> */
    private array $originalPost;

    /** @var array<string, mixed> */
    private array $originalCookie;

    /** @var array<string, mixed> */
    private array $originalFiles;

    /** @var array<string, mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        $this->originalCookie = $_COOKIE;
        $this->originalFiles = $_FILES;
        $this->originalServer = $_SERVER;

        RequestFacade::clearRequest();
        RouteFacade::clearRouter();
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_COOKIE = $this->originalCookie;
        $_FILES = $this->originalFiles;
        $_SERVER = $this->originalServer;

        RequestFacade::clearRequest();
        RouteFacade::clearRouter();
    }

    public function testProviderRegistersRouterAliasAndFacade(): void
    {
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/health',
        ];

        $app = new Application();
        $app->register(RequestServiceProvider::class);
        $app->register(RouteServiceProvider::class);
        $app->boot();

        $router = $app->make(Router::class);
        $router->get('/health', static fn (HttpRequest $request): string => $request->path());

        self::assertSame($router, $app->make('router'));
        self::assertSame($router, RouteFacade::getRouter());
        self::assertSame('/health', RouteFacade::dispatch());
    }
}
