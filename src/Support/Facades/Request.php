<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use BadMethodCallException;
use Myxa\Http\Request as HttpRequest;

/**
 * Small static facade for the current HTTP request.
 */
final class Request
{
    private static ?HttpRequest $request = null;

    /**
     * Set the underlying request instance used by the facade.
     */
    public static function setRequest(HttpRequest $request): void
    {
        self::$request = $request;
    }

    /**
     * Clear the currently stored request instance.
     */
    public static function clearRequest(): void
    {
        self::$request = null;
    }

    /**
     * Return the underlying request instance.
     */
    public static function getRequest(): HttpRequest
    {
        return self::$request ??= HttpRequest::capture();
    }

    /**
     * Return the normalized HTTP method.
     */
    public static function method(): string
    {
        return self::getRequest()->method();
    }

    /**
     * Determine whether the request matches the given HTTP method.
     */
    public static function isMethod(string $method): bool
    {
        return self::getRequest()->isMethod($method);
    }

    /**
     * Return a query string value or the full query array.
     */
    public static function query(?string $key = null, mixed $default = null): mixed
    {
        return self::getRequest()->query($key, $default);
    }

    /**
     * Return a POST body value or the full POST array.
     */
    public static function post(?string $key = null, mixed $default = null): mixed
    {
        return self::getRequest()->post($key, $default);
    }

    /**
     * Return an input value from merged query and POST data, or all input.
     */
    public static function input(?string $key = null, mixed $default = null): mixed
    {
        return self::getRequest()->input($key, $default);
    }

    /**
     * Return all merged query and POST input data.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::getRequest()->all();
    }

    /**
     * Return a cookie value or the full cookie array.
     */
    public static function cookie(?string $key = null, mixed $default = null): mixed
    {
        return self::getRequest()->cookie($key, $default);
    }

    /**
     * Return an uploaded file wrapper or the full normalized uploaded files map.
     */
    public static function file(?string $key = null, mixed $default = null): mixed
    {
        return self::getRequest()->file($key, $default);
    }

    /**
     * Return a raw PHP `$_FILES` entry or the full files array.
     */
    public static function rawFile(?string $key = null, mixed $default = null): mixed
    {
        return self::getRequest()->rawFile($key, $default);
    }

    /**
     * Return a server value or the full server array.
     */
    public static function server(?string $key = null, mixed $default = null): mixed
    {
        return self::getRequest()->server($key, $default);
    }

    /**
     * Return a header value or the full normalized header map.
     */
    public static function header(?string $name = null, mixed $default = null): mixed
    {
        return self::getRequest()->header($name, $default);
    }

    /**
     * Return all headers with formatted header names.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return self::getRequest()->headers();
    }

    /**
     * Return the resolved request scheme.
     */
    public static function scheme(): string
    {
        return self::getRequest()->scheme();
    }

    /**
     * Determine whether the request uses HTTPS.
     */
    public static function secure(): bool
    {
        return self::getRequest()->secure();
    }

    /**
     * Return the resolved request host.
     */
    public static function host(): string
    {
        return self::getRequest()->host();
    }

    /**
     * Return the resolved request port.
     */
    public static function port(): ?int
    {
        return self::getRequest()->port();
    }

    /**
     * Return the request path without the query string.
     */
    public static function path(): string
    {
        return self::getRequest()->path();
    }

    /**
     * Return the raw request URI.
     */
    public static function requestUri(): string
    {
        return self::getRequest()->requestUri();
    }

    /**
     * Return the request query string.
     */
    public static function queryString(): string
    {
        return self::getRequest()->queryString();
    }

    /**
     * Return the request URL without the query string.
     */
    public static function url(): string
    {
        return self::getRequest()->url();
    }

    /**
     * Return the full request URL including the query string.
     */
    public static function fullUrl(): string
    {
        return self::getRequest()->fullUrl();
    }

    /**
     * Determine whether the request was made through XMLHttpRequest.
     */
    public static function ajax(): bool
    {
        return self::getRequest()->ajax();
    }

    /**
     * Return the bearer token from the Authorization header when present.
     */
    public static function bearerToken(): ?string
    {
        return self::getRequest()->bearerToken();
    }

    /**
     * Return the client IP address when available.
     */
    public static function ip(): ?string
    {
        return self::getRequest()->ip();
    }

    /**
     * Return the raw request body content.
     */
    public static function content(): string
    {
        return self::getRequest()->content();
    }

    /**
     * Forward unknown static calls to the underlying request instance.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if (!method_exists(self::getRequest(), $name)) {
            throw new BadMethodCallException(sprintf('Request facade method "%s" is not supported.', $name));
        }

        return self::getRequest()->{$name}(...$arguments);
    }
}
