<?php

declare(strict_types=1);

namespace Test\Unit\Http;

use Myxa\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function testRequestNormalizesHttpStateAndExposesHelpers(): void
    {
        $request = new Request(
            query: ['page' => '2'],
            post: ['search' => 'myxa'],
            cookies: ['session' => 'abc123'],
            files: ['avatar' => ['name' => 'avatar.png']],
            server: [
                'REQUEST_METHOD' => 'post',
                'REQUEST_URI' => '/users/list?page=2',
                'QUERY_STRING' => 'page=2',
                'HTTPS' => 'on',
                'HTTP_HOST' => 'example.com',
                'SERVER_PORT' => '443',
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: '{"search":"myxa"}',
        );

        self::assertSame('POST', $request->method());
        self::assertTrue($request->isMethod('post'));
        self::assertSame('2', $request->query('page'));
        self::assertSame('myxa', $request->post('search'));
        self::assertSame('myxa', $request->input('search'));
        self::assertSame('2', $request->input('page'));
        self::assertSame(['page' => '2', 'search' => 'myxa'], $request->all());
        self::assertSame('abc123', $request->cookie('session'));
        self::assertSame(['name' => 'avatar.png'], $request->file('avatar'));
        self::assertSame('application/json', $request->header('content-type'));
        self::assertSame('XMLHttpRequest', $request->header('X-Requested-With'));
        self::assertTrue($request->ajax());
        self::assertTrue($request->secure());
        self::assertSame('example.com', $request->host());
        self::assertSame(443, $request->port());
        self::assertSame('/users/list', $request->path());
        self::assertSame('/users/list?page=2', $request->requestUri());
        self::assertSame('page=2', $request->queryString());
        self::assertSame('https://example.com/users/list', $request->url());
        self::assertSame('https://example.com/users/list?page=2', $request->fullUrl());
        self::assertSame('127.0.0.1', $request->ip());
        self::assertSame('{"search":"myxa"}', $request->content());
    }

    public function testRequestFallsBackToGeneratedUriAndDefaults(): void
    {
        $request = new Request(
            query: ['filter' => 'active'],
            server: [
                'REQUEST_METHOD' => '',
                'SERVER_NAME' => 'localhost',
            ],
        );

        self::assertSame('GET', $request->method());
        self::assertSame('/', $request->path());
        self::assertSame('/?filter=active', $request->requestUri());
        self::assertSame('filter=active', $request->queryString());
        self::assertSame('http://localhost/', $request->url());
        self::assertSame('http://localhost/?filter=active', $request->fullUrl());
        self::assertFalse($request->secure());
        self::assertFalse($request->ajax());
        self::assertNull($request->ip());
    }
}
