<?php

declare(strict_types=1);

namespace Test\Unit\Http;

use Myxa\Application;
use Myxa\Http\Request as HttpRequest;
use Myxa\Http\RequestServiceProvider;
use Myxa\Support\Facades\Request as RequestFacade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(HttpRequest::class)]
#[CoversClass(RequestFacade::class)]
#[CoversClass(RequestServiceProvider::class)]
final class RequestServiceProviderTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_COOKIE = $this->originalCookie;
        $_FILES = $this->originalFiles;
        $_SERVER = $this->originalServer;

        RequestFacade::clearRequest();
    }

    public function testProviderRegistersRequestSingletonAliasAndFacade(): void
    {
        $_GET = ['page' => '7'];
        $_POST = ['search' => 'owl'];
        $_COOKIE = ['session' => 'xyz'];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/search?page=7',
            'QUERY_STRING' => 'page=7',
            'HTTP_HOST' => 'framework.test',
            'SERVER_PORT' => '80',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ];

        $app = new Application();
        $app->register(RequestServiceProvider::class);
        $app->boot();

        $request = $app->make(HttpRequest::class);

        self::assertInstanceOf(HttpRequest::class, $request);
        self::assertSame($request, $app->make('request'));
        self::assertSame($request, RequestFacade::getRequest());
        self::assertSame('POST', RequestFacade::method());
        self::assertSame('/search', RequestFacade::path());
        self::assertSame('owl', RequestFacade::input('search'));
        self::assertSame('7', RequestFacade::query('page'));
        self::assertTrue(RequestFacade::ajax());
    }
}
