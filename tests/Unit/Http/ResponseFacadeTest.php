<?php

declare(strict_types=1);

namespace Test\Unit\Http;

use BadMethodCallException;
use JsonException;
use Myxa\Http\Response as HttpResponse;
use Myxa\Http\StreamWriterInterface;
use Myxa\Support\Facades\Response as ResponseFacade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseFacade::class)]
#[CoversClass(HttpResponse::class)]
final class ResponseFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        ResponseFacade::clearResponse();
    }

    /**
     * @throws JsonException
     */
    public function testFacadeDelegatesToCurrentResponseInstance(): void
    {
        $response = new HttpResponse();

        ResponseFacade::setResponse($response);
        ResponseFacade::status(202);
        ResponseFacade::setHeader('X-App', 'myxa');
        ResponseFacade::body('Hello');
        ResponseFacade::append(' world');
        ResponseFacade::cookie('session', 'abc');

        self::assertSame('Hello world', ResponseFacade::content());
        self::assertSame(['X-App' => 'myxa'], ResponseFacade::headers());
        self::assertTrue(ResponseFacade::hasHeader('x-app'));
        self::assertSame([
            'session' => [
                'value' => 'abc',
                'expires' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ], ResponseFacade::cookies());

        ResponseFacade::removeHeader('X-App');
        ResponseFacade::removeCookie('session');

        self::assertFalse(ResponseFacade::hasHeader('X-App'));
        self::assertFalse(ResponseFacade::hasCookie('session'));

        ResponseFacade::text('plain', 201);
        self::assertSame(201, ResponseFacade::statusCode());
        self::assertSame('text/plain; charset=UTF-8', ResponseFacade::header('content-type'));
        self::assertSame('plain', ResponseFacade::content());

        ResponseFacade::html('<p>html</p>');
        self::assertSame('text/html; charset=UTF-8', ResponseFacade::header('content-type'));
        self::assertSame('<p>html</p>', ResponseFacade::content());

        ResponseFacade::json(['ok' => true], 202);
        self::assertSame(202, ResponseFacade::statusCode());
        self::assertSame('application/json; charset=UTF-8', ResponseFacade::header('content-type'));
        self::assertSame('{"ok":true}', ResponseFacade::content());

        ResponseFacade::redirect('/login', 301);
        self::assertSame(301, ResponseFacade::statusCode());
        self::assertSame('/login', ResponseFacade::header('location'));
        self::assertSame('', ResponseFacade::content());

        ResponseFacade::noContent();
        self::assertSame(204, ResponseFacade::statusCode());
        self::assertSame('', ResponseFacade::content());

        ResponseFacade::setHeader('X-Sent', 'yes');
        ResponseFacade::cookie('session', 'abc', sameSite: null);
        ResponseFacade::body('sent');

        self::assertSame($response, ResponseFacade::getResponse());

        if (function_exists('header_remove')) {
            header_remove();
        }

        ob_start();
        ResponseFacade::send();
        $output = ob_get_clean();

        self::assertSame('sent', $output);

        if (function_exists('header_remove')) {
            header_remove();
        }
    }

    public function testFacadeSupportsStreamingResponses(): void
    {
        $response = new HttpResponse();

        ResponseFacade::setResponse($response);
        ResponseFacade::streaming(static function (StreamWriterInterface $stream): void {
            $stream->write('hello');
            $stream->write(' world');
        }, 202, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);

        self::assertSame($response, ResponseFacade::getResponse());
        self::assertTrue($response->isStreaming());
        self::assertSame(202, ResponseFacade::statusCode());
        self::assertSame('text/plain; charset=UTF-8', ResponseFacade::header('content-type'));

        if (function_exists('header_remove')) {
            header_remove();
        }

        ob_start();
        ob_start();
        ResponseFacade::send();
        ob_end_clean();
        $output = ob_get_clean();

        self::assertSame('hello world', $output);

        if (function_exists('header_remove')) {
            header_remove();
        }
    }

    public function testFacadeMagicCallStaticForwardsToUnderlyingResponse(): void
    {
        $response = (new HttpResponse())->body('magic');

        ResponseFacade::setResponse($response);

        self::assertSame('magic', ResponseFacade::__callStatic('content', []));
    }

    public function testFacadeThrowsClearExceptionForUnknownMethod(): void
    {
        ResponseFacade::setResponse(new HttpResponse());

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Response facade method "foobar" is not supported.');

        ResponseFacade::foobar();
    }
}
