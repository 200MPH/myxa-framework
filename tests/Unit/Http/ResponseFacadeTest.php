<?php

declare(strict_types=1);

namespace Test\Unit\Http;

use JsonException;
use Myxa\Http\Response as HttpResponse;
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
        ResponseFacade::append('Hello');
        ResponseFacade::append(' world');
        ResponseFacade::cookie('session', 'abc');
        ResponseFacade::json(['ok' => true], 202);

        self::assertSame($response, ResponseFacade::getResponse());
        self::assertSame(202, ResponseFacade::statusCode());
        self::assertSame('application/json; charset=UTF-8', ResponseFacade::header('content-type'));
        self::assertSame('myxa', ResponseFacade::header('x-app'));
        self::assertTrue(ResponseFacade::hasCookie('session'));
        self::assertSame('{"ok":true}', ResponseFacade::content());
    }
}
