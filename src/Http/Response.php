<?php

declare(strict_types=1);

namespace Myxa\Http;

use InvalidArgumentException;
use JsonException;

/**
 * Lightweight HTTP response object for the current app runtime.
 *
 * It keeps Myxa's HTTP layer small and practical while still covering the
 * common response concerns: status, headers, cookies, body content, redirects,
 * and JSON/text helpers.
 */
final class Response
{
    private int $statusCode = 200;

    private string $content = '';

    /**
     * @var array<string, array{name: string, value: string}>
     */
    private array $headers = [];

    /**
     * @var array<string, array{
     *     value: string,
     *     expires: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string|null
     * }>
     */
    private array $cookies = [];

    /**
     * @param array<string, scalar|null> $headers
     */
    public function __construct(string $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->status($statusCode);
        $this->content = $content;

        foreach ($headers as $name => $value) {
            $this->setHeader($name, (string) $value);
        }
    }

    /**
     * Set the HTTP status code.
     */
    public function status(int $statusCode): self
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP status code [%d].', $statusCode));
        }

        $this->statusCode = $statusCode;

        return $this;
    }

    /**
     * Return the current HTTP status code.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Replace the response body content.
     */
    public function body(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Append content to the current response body.
     */
    public function append(string $content): self
    {
        $this->content .= $content;

        return $this;
    }

    /**
     * Return the current response body content.
     */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * Set or replace a response header value.
     */
    public function setHeader(string $name, string $value): self
    {
        $normalizedName = $this->normalizeHeaderLookupName($name);

        $this->headers[$normalizedName] = [
            'name' => $this->formatHeaderName($normalizedName),
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Return a response header value when present.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[$this->normalizeHeaderLookupName($name)]['value'] ?? $default;
    }

    /**
     * Return all response headers with formatted header names.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->headers as $header) {
            $headers[$header['name']] = $header['value'];
        }

        return $headers;
    }

    /**
     * Determine whether the response contains the given header.
     */
    public function hasHeader(string $name): bool
    {
        return array_key_exists($this->normalizeHeaderLookupName($name), $this->headers);
    }

    /**
     * Remove a response header when present.
     */
    public function removeHeader(string $name): self
    {
        unset($this->headers[$this->normalizeHeaderLookupName($name)]);

        return $this;
    }

    /**
     * Set a plain-text response body and content type.
     */
    public function text(string $content, int $statusCode = 200): self
    {
        return $this
            ->status($statusCode)
            ->setHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->body($content);
    }

    /**
     * Set an HTML response body and content type.
     */
    public function html(string $content, int $statusCode = 200): self
    {
        return $this
            ->status($statusCode)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->body($content);
    }

    /**
     * JSON-encode the payload and set the JSON content type.
     *
     * @throws JsonException
     */
    public function json(mixed $data, int $statusCode = 200, int $flags = 0): self
    {
        $encoded = json_encode($data, \JSON_THROW_ON_ERROR | $flags);
        if (!is_string($encoded)) {
            throw new JsonException('Failed to encode response JSON payload.');
        }

        return $this
            ->status($statusCode)
            ->setHeader('Content-Type', 'application/json; charset=UTF-8')
            ->body($encoded);
    }

    /**
     * Configure the response as an HTTP redirect.
     */
    public function redirect(string $url, int $statusCode = 302): self
    {
        return $this
            ->status($statusCode)
            ->setHeader('Location', $url)
            ->body('');
    }

    /**
     * Clear the body and prepare an empty response.
     */
    public function noContent(int $statusCode = 204): self
    {
        return $this
            ->status($statusCode)
            ->removeHeader('Content-Type')
            ->body('');
    }

    /**
     * Queue a cookie to be sent with the response.
     */
    public function cookie(
        string $name,
        string $value = '',
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        ?string $sameSite = 'Lax',
    ): self {
        if ($name === '') {
            throw new InvalidArgumentException('Cookie name cannot be empty.');
        }

        $this->cookies[$name] = [
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $this->normalizeSameSite($sameSite),
        ];

        return $this;
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
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Determine whether the response contains the given cookie.
     */
    public function hasCookie(string $name): bool
    {
        return array_key_exists($name, $this->cookies);
    }

    /**
     * Remove a queued cookie when present.
     */
    public function removeCookie(string $name): self
    {
        unset($this->cookies[$name]);

        return $this;
    }

    /**
     * Send the response status, headers, cookies, and body to the client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);

            foreach ($this->headers as $header) {
                header(sprintf('%s: %s', $header['name'], $header['value']), true);
            }

            foreach ($this->cookies as $name => $cookie) {
                $options = [
                    'expires' => $cookie['expires'],
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httponly'],
                ];

                if ($cookie['samesite'] !== null) {
                    $options['samesite'] = $cookie['samesite'];
                }

                setcookie($name, $cookie['value'], $options);
            }
        }

        echo $this->content;
    }

    private function normalizeHeaderLookupName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Header name cannot be empty.');
        }

        return strtolower(str_replace('_', '-', $name));
    }

    private function formatHeaderName(string $name): string
    {
        return implode('-', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            explode('-', $this->normalizeHeaderLookupName($name)),
        ));
    }

    private function normalizeSameSite(?string $sameSite): ?string
    {
        if ($sameSite === null || trim($sameSite) === '') {
            return null;
        }

        $normalized = ucfirst(strtolower(trim($sameSite)));
        if (!in_array($normalized, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported SameSite value [%s].', $sameSite));
        }

        return $normalized;
    }
}
