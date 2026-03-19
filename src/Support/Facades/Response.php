<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use JsonException;
use Myxa\Http\Response as HttpResponse;

/**
 * Small static facade for the current HTTP response.
 */
final class Response
{
    private static ?HttpResponse $response = null;

    /**
     * Set the underlying response instance used by the facade.
     */
    public static function setResponse(HttpResponse $response): void
    {
        self::$response = $response;
    }

    /**
     * Clear the currently stored response instance.
     */
    public static function clearResponse(): void
    {
        self::$response = null;
    }

    /**
     * Return the underlying response instance.
     */
    public static function getResponse(): HttpResponse
    {
        return self::$response ??= new HttpResponse();
    }

    /**
     * Set the HTTP status code.
     */
    public static function status(int $statusCode): HttpResponse
    {
        return self::getResponse()->status($statusCode);
    }

    /**
     * Return the current HTTP status code.
     */
    public static function statusCode(): int
    {
        return self::getResponse()->statusCode();
    }

    /**
     * Replace the response body content.
     */
    public static function body(string $content): HttpResponse
    {
        return self::getResponse()->body($content);
    }

    /**
     * Append content to the current response body.
     */
    public static function append(string $content): HttpResponse
    {
        return self::getResponse()->append($content);
    }

    /**
     * Return the current response body content.
     */
    public static function content(): string
    {
        return self::getResponse()->content();
    }

    /**
     * Set or replace a response header value.
     */
    public static function setHeader(string $name, string $value): HttpResponse
    {
        return self::getResponse()->setHeader($name, $value);
    }

    /**
     * Return a response header value when present.
     */
    public static function header(string $name, ?string $default = null): ?string
    {
        return self::getResponse()->header($name, $default);
    }

    /**
     * Return all response headers with formatted header names.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return self::getResponse()->headers();
    }

    /**
     * Determine whether the response contains the given header.
     */
    public static function hasHeader(string $name): bool
    {
        return self::getResponse()->hasHeader($name);
    }

    /**
     * Remove a response header when present.
     */
    public static function removeHeader(string $name): HttpResponse
    {
        return self::getResponse()->removeHeader($name);
    }

    /**
     * Set a plain-text response body and content type.
     */
    public static function text(string $content, int $statusCode = 200): HttpResponse
    {
        return self::getResponse()->text($content, $statusCode);
    }

    /**
     * Set an HTML response body and content type.
     */
    public static function html(string $content, int $statusCode = 200): HttpResponse
    {
        return self::getResponse()->html($content, $statusCode);
    }

    /**
     * JSON-encode the payload and set the JSON content type.
     *
     * @throws JsonException
     */
    public static function json(mixed $data, int $statusCode = 200, int $flags = 0): HttpResponse
    {
        return self::getResponse()->json($data, $statusCode, $flags);
    }

    /**
     * Configure the response as an HTTP redirect.
     */
    public static function redirect(string $url, int $statusCode = 302): HttpResponse
    {
        return self::getResponse()->redirect($url, $statusCode);
    }

    /**
     * Clear the body and prepare an empty response.
     */
    public static function noContent(int $statusCode = 204): HttpResponse
    {
        return self::getResponse()->noContent($statusCode);
    }

    /**
     * Queue a cookie to be sent with the response.
     */
    public static function cookie(
        string $name,
        string $value = '',
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        ?string $sameSite = 'Lax',
    ): HttpResponse {
        return self::getResponse()->cookie($name, $value, $expires, $path, $domain, $secure, $httpOnly, $sameSite);
    }

    /**
     * Return all queued cookies.
     *
     * @return array<string, array{
     *     value: string,
     *     expires: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string|null
     * }>
     */
    public static function cookies(): array
    {
        return self::getResponse()->cookies();
    }

    /**
     * Determine whether the response contains the given cookie.
     */
    public static function hasCookie(string $name): bool
    {
        return self::getResponse()->hasCookie($name);
    }

    /**
     * Remove a queued cookie when present.
     */
    public static function removeCookie(string $name): HttpResponse
    {
        return self::getResponse()->removeCookie($name);
    }

    /**
     * Send the response status, headers, cookies, and body to the client.
     */
    public static function send(): void
    {
        self::getResponse()->send();
    }

    /**
     * Forward unknown static calls to the underlying response instance.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        return self::getResponse()->{$name}(...$arguments);
    }
}
