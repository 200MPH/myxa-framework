<?php

declare(strict_types=1);

namespace Test\Unit\Http;

use Myxa\Http\Request as HttpRequest;
use Myxa\Support\Facades\Request as RequestFacade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestFacade::class)]
#[CoversClass(HttpRequest::class)]
final class RequestFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestFacade::clearRequest();
    }

    public function testFacadeDelegatesToCurrentRequestInstance(): void
    {
        $request = new HttpRequest(
            query: ['page' => '3'],
            post: ['name' => 'Myxa'],
            server: [
                'REQUEST_METHOD' => 'PATCH',
                'REQUEST_URI' => '/profile?page=3',
                'QUERY_STRING' => 'page=3',
                'HTTP_HOST' => 'example.test:8080',
                'SERVER_PORT' => '8080',
                'REMOTE_ADDR' => '10.0.0.1',
            ],
        );

        RequestFacade::setRequest($request);

        self::assertSame($request, RequestFacade::getRequest());
        self::assertSame('PATCH', RequestFacade::method());
        self::assertTrue(RequestFacade::isMethod('patch'));
        self::assertSame('3', RequestFacade::query('page'));
        self::assertSame('Myxa', RequestFacade::input('name'));
        self::assertSame('/profile', RequestFacade::path());
        self::assertSame('http://example.test:8080/profile?page=3', RequestFacade::fullUrl());
        self::assertSame('10.0.0.1', RequestFacade::ip());
    }
}
