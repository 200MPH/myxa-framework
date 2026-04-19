<?php

declare(strict_types=1);

namespace Test\Unit\Http;

use BadMethodCallException;
use Myxa\Http\Request as HttpRequest;
use Myxa\Storage\UploadedFile;
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
            cookies: ['theme' => 'forest'],
            files: ['avatar' => [
                'name' => 'avatar.png',
                'type' => 'image/png',
                'size' => 123,
                'tmp_name' => '/tmp/avatar.png',
                'error' => 0,
            ]],
            server: [
                'REQUEST_METHOD' => 'PATCH',
                'REQUEST_URI' => '/profile?page=3',
                'QUERY_STRING' => 'page=3',
                'HTTP_HOST' => 'example.test:8080',
                'SERVER_PORT' => '8080',
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'REMOTE_ADDR' => '10.0.0.1',
            ],
            content: '{"name":"Myxa"}',
        );

        RequestFacade::setRequest($request);

        self::assertSame($request, RequestFacade::getRequest());
        self::assertSame('PATCH', RequestFacade::method());
        self::assertTrue(RequestFacade::isMethod('patch'));
        self::assertSame('3', RequestFacade::query('page'));
        self::assertSame(['page' => '3'], RequestFacade::query());
        self::assertSame('Myxa', RequestFacade::post('name'));
        self::assertSame('Myxa', RequestFacade::input('name'));
        self::assertSame(['page' => '3', 'name' => 'Myxa'], RequestFacade::all());
        self::assertSame('forest', RequestFacade::cookie('theme'));
        self::assertInstanceOf(UploadedFile::class, RequestFacade::file('avatar'));
        self::assertSame('avatar.png', RequestFacade::file('avatar')->name());
        self::assertSame([
            'name' => 'avatar.png',
            'type' => 'image/png',
            'size' => 123,
            'tmp_name' => '/tmp/avatar.png',
            'error' => 0,
        ], RequestFacade::rawFile('avatar'));
        self::assertSame('PATCH', RequestFacade::server('REQUEST_METHOD'));
        self::assertSame('application/json', RequestFacade::header('content-type'));
        self::assertSame([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Host' => 'example.test:8080',
            'X-Requested-With' => 'XMLHttpRequest',
        ], RequestFacade::headers());
        self::assertSame('http', RequestFacade::scheme());
        self::assertFalse(RequestFacade::secure());
        self::assertSame('example.test', RequestFacade::host());
        self::assertSame(8080, RequestFacade::port());
        self::assertSame('/profile', RequestFacade::path());
        self::assertSame('/profile?page=3', RequestFacade::requestUri());
        self::assertSame('page=3', RequestFacade::queryString());
        self::assertSame('http://example.test:8080/profile', RequestFacade::url());
        self::assertSame('http://example.test:8080/profile?page=3', RequestFacade::fullUrl());
        self::assertTrue(RequestFacade::ajax());
        self::assertSame('10.0.0.1', RequestFacade::ip());
        self::assertSame('{"name":"Myxa"}', RequestFacade::content());
    }

    public function testFacadeMagicCallStaticForwardsToUnderlyingRequest(): void
    {
        RequestFacade::setRequest(new HttpRequest(server: ['REQUEST_URI' => '/magic']));

        self::assertSame('/magic', RequestFacade::__callStatic('path', []));
    }

    public function testFacadeThrowsClearExceptionForUnknownMethod(): void
    {
        RequestFacade::setRequest(new HttpRequest());

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Request facade method "foobar" is not supported.');

        RequestFacade::foobar();
    }
}
