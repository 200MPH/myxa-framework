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

    public function testRequestParsesAbsoluteUrisForwardedProtocolsAndIpv6Hosts(): void
    {
        $request = new Request(
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => 'https://[2001:db8::1]:8443/articles?draft=1',
                'HTTP_HOST' => '[2001:db8::1]:8443',
                'HTTP_X_FORWARDED_PROTO' => 'https, http',
            ],
        );

        self::assertSame('/articles?draft=1', $request->requestUri());
        self::assertSame('/articles', $request->path());
        self::assertSame('draft=1', $request->queryString());
        self::assertSame('https', $request->scheme());
        self::assertSame('[2001:db8::1]', $request->host());
        self::assertSame(8443, $request->port());
        self::assertSame('https://[2001:db8::1]:8443/articles', $request->url());
        self::assertSame('https://[2001:db8::1]:8443/articles?draft=1', $request->fullUrl());
    }

    public function testRequestReturnsFullCollectionsAndFormattedHeaders(): void
    {
        $request = new Request(
            query: ['page' => '1'],
            post: ['name' => 'Myxa'],
            cookies: ['theme' => 'forest'],
            files: ['avatar' => ['name' => 'avatar.png']],
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/profile',
                'REQUEST_SCHEME' => 'https',
                'SERVER_PORT' => 'abc',
                'HTTP_HOST' => 'files.test',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US',
                'CONTENT_LENGTH' => '123',
                'REMOTE_ADDR' => '192.168.1.10',
            ],
        );

        self::assertSame(['page' => '1'], $request->query());
        self::assertSame(['name' => 'Myxa'], $request->post());
        self::assertSame(['theme' => 'forest'], $request->cookie());
        self::assertSame(['avatar' => ['name' => 'avatar.png']], $request->file());
        self::assertSame('forest', $request->cookie('theme'));
        self::assertSame('fallback', $request->cookie('missing', 'fallback'));
        self::assertSame('fallback', $request->file('missing', 'fallback'));
        self::assertSame('192.168.1.10', $request->server('REMOTE_ADDR'));
        self::assertSame([
            'Accept-Language' => 'en-US',
            'Content-Length' => '123',
            'Host' => 'files.test',
        ], $request->headers());
        self::assertSame($request->headers(), $request->header());
        self::assertSame('en-US', $request->header('accept-language'));
        self::assertSame('https', $request->scheme());
        self::assertTrue($request->secure());
        self::assertSame(443, $request->port());
    }

    public function testRequestFallsBackToLocalhostAndIgnoresInvalidHeaderEntries(): void
    {
        $request = new Request(
            server: [
                0 => 'ignore me',
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '   ',
                'SERVER_NAME' => '   ',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_COMPLEX' => ['skip me'],
            ],
        );

        self::assertSame('/', $request->requestUri());
        self::assertSame('/', $request->path());
        self::assertSame('', $request->queryString());
        self::assertSame('localhost', $request->host());
        self::assertSame('http://localhost/', $request->url());
        self::assertSame($request->url(), $request->fullUrl());
        self::assertSame(['Content-Type' => 'application/json'], $request->headers());
    }
}
