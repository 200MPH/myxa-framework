<?php

declare(strict_types=1);

namespace Test\Unit\Http;

use InvalidArgumentException;
use JsonException;
use Myxa\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    public function testResponseTracksStatusHeadersAndBody(): void
    {
        $response = (new Response())
            ->status(201)
            ->setHeader('X-Trace-Id', 'abc123')
            ->setHeader('content-type', 'text/plain; charset=UTF-8')
            ->body('Hello')
            ->append(' world');

        self::assertSame(201, $response->statusCode());
        self::assertSame('abc123', $response->header('x-trace-id'));
        self::assertSame('text/plain; charset=UTF-8', $response->header('Content-Type'));
        self::assertTrue($response->hasHeader('CONTENT_TYPE'));
        self::assertSame([
            'X-Trace-Id' => 'abc123',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], $response->headers());
        self::assertSame('Hello world', $response->content());

        $response->removeHeader('X-Trace-Id');

        self::assertFalse($response->hasHeader('X-Trace-Id'));
        self::assertNull($response->header('X-Trace-Id'));
    }

    /**
     * @throws JsonException
     */
    public function testResponseHelpersPrepareSpecializedPayloads(): void
    {
        $text = (new Response())->text('Accepted', 202);
        self::assertSame(202, $text->statusCode());
        self::assertSame('text/plain; charset=UTF-8', $text->header('Content-Type'));
        self::assertSame('Accepted', $text->content());

        $html = (new Response())->html('<h1>Hello</h1>');
        self::assertSame('text/html; charset=UTF-8', $html->header('Content-Type'));
        self::assertSame('<h1>Hello</h1>', $html->content());

        $json = (new Response())->json(['ok' => true], 201);
        self::assertSame(201, $json->statusCode());
        self::assertSame('application/json; charset=UTF-8', $json->header('Content-Type'));
        self::assertSame('{"ok":true}', $json->content());

        $redirect = (new Response('stale'))->redirect('/login');
        self::assertSame(302, $redirect->statusCode());
        self::assertSame('/login', $redirect->header('Location'));
        self::assertSame('', $redirect->content());

        $empty = (new Response('payload', 200, ['Content-Type' => 'text/plain']))->noContent();
        self::assertSame(204, $empty->statusCode());
        self::assertSame('', $empty->content());
        self::assertFalse($empty->hasHeader('Content-Type'));
    }

    public function testResponseQueuesAndRemovesCookies(): void
    {
        $response = (new Response())->cookie(
            name: 'session',
            value: 'token',
            expires: 3600,
            path: '/',
            domain: 'example.com',
            secure: true,
            httpOnly: true,
            sameSite: 'strict',
        );

        self::assertTrue($response->hasCookie('session'));
        self::assertSame([
            'session' => [
                'value' => 'token',
                'expires' => 3600,
                'path' => '/',
                'domain' => 'example.com',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ],
        ], $response->cookies());

        $response->removeCookie('session');

        self::assertFalse($response->hasCookie('session'));
        self::assertSame([], $response->cookies());
    }

    public function testResponseSendOutputsBodyAndHandlesSameSiteCookies(): void
    {
        $response = (new Response())
            ->setHeader('X-App', 'myxa')
            ->cookie('session', 'token', sameSite: 'strict')
            ->body('sent');

        if (function_exists('header_remove')) {
            header_remove();
        }

        ob_start();
        $response->send();
        $output = ob_get_clean();

        self::assertSame('sent', $output);

        if (function_exists('header_remove')) {
            header_remove();
        }
    }

    public function testResponseRejectsInvalidStatusCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP status code [99].');

        (new Response())->status(99);
    }

    public function testResponseRejectsInvalidSameSiteValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported SameSite value [sideways].');

        (new Response())->cookie('session', 'token', sameSite: 'sideways');
    }

    public function testResponseRejectsEmptyHeaderName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name cannot be empty.');

        (new Response())->setHeader('   ', 'value');
    }

    public function testResponseRejectsEmptyCookieName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie name cannot be empty.');

        (new Response())->cookie('', 'value');
    }
}
