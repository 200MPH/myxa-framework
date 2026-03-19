<?php

declare(strict_types=1);

namespace Test\Unit\Http;

use Myxa\Application;
use Myxa\Http\Response as HttpResponse;
use Myxa\Http\ResponseServiceProvider;
use Myxa\Support\Facades\Response as ResponseFacade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(HttpResponse::class)]
#[CoversClass(ResponseFacade::class)]
#[CoversClass(ResponseServiceProvider::class)]
final class ResponseServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        ResponseFacade::clearResponse();
    }

    public function testProviderRegistersResponseSingletonAndFacade(): void
    {
        $app = new Application();
        $app->register(ResponseServiceProvider::class);
        $app->boot();

        $response = $app->make(HttpResponse::class);

        self::assertInstanceOf(HttpResponse::class, $response);
        self::assertSame($response, $app->make(HttpResponse::class));
        self::assertSame($response, ResponseFacade::getResponse());

        ResponseFacade::text('Created', 201);

        self::assertSame(201, $response->statusCode());
        self::assertSame('text/plain; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame('Created', $response->content());
    }
}
