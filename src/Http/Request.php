<?php

declare(strict_types=1);

namespace Myxa\Http;

use Myxa\Storage\UploadedFile;

/**
 * Lightweight HTTP request object for the current app runtime.
 *
 * It intentionally stays framework-native for now instead of implementing the
 * full PSR-7 surface, while still following common HTTP semantics such as
 * case-insensitive header access and normalized request methods.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, mixed> */
    private array $post;

    /** @var array<string, mixed> */
    private array $cookies;

    /** @var array<string, mixed> */
    private array $files;

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, string> */
    private array $headers;

    private string $method;

    private string $requestUri;

    private string $path;

    private string $queryString;

    private string $scheme;

    private string $host;

    private ?int $port;

    private string $content;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        array $query = [],
        array $post = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
    ) {
        $this->query = $query;
        $this->post = $post;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->server = $server;
        $this->headers = $this->extractHeaders($server);
        $this->method = $this->normalizeMethod((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $this->requestUri = $this->normalizeRequestUri((string) ($server['REQUEST_URI'] ?? '/'), $query);
        $this->path = $this->resolvePath($this->requestUri);
        $this->queryString = $this->resolveQueryString($server, $this->requestUri, $query);
        $this->scheme = $this->resolveScheme($server);
        $this->host = $this->resolveHost($server);
        $this->port = $this->resolvePort($server, $this->scheme);
        $this->content = $content ?? '';
    }

    /**
     * Capture a request instance from the current PHP globals.
     */
    public static function capture(): self
    {
        $content = file_get_contents('php://input');

        return new self(
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
            $_SERVER,
            is_string($content) ? $content : null,
        );
    }

    /**
     * Return the normalized HTTP method.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Determine whether the request matches the given HTTP method.
     */
    public function isMethod(string $method): bool
    {
        return $this->method === $this->normalizeMethod($method);
    }

    /**
     * Return a query string value or the full query array.
     *
     * @return array<string, mixed>|mixed
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $this->valueFrom($this->query, $key, $default);
    }

    /**
     * Return a POST body value or the full POST array.
     *
     * @return array<string, mixed>|mixed
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        return $this->valueFrom($this->post, $key, $default);
    }

    /**
     * Return an input value from merged query and POST data, or all input.
     *
     * @return array<string, mixed>|mixed
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $input = $this->all();

        return $this->valueFrom($input, $key, $default);
    }

    /**
     * Return all merged query and POST input data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->query, $this->post);
    }

    /**
     * Return a cookie value or the full cookie array.
     *
     * @return array<string, mixed>|mixed
     */
    public function cookie(?string $key = null, mixed $default = null): mixed
    {
        return $this->valueFrom($this->cookies, $key, $default);
    }

    /**
     * Return an uploaded file wrapper or the full normalized uploaded files map.
     *
     * @return array<string, mixed>|UploadedFile|mixed
     */
    public function file(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->normalizeUploadedFiles($this->files);
        }

        if (!array_key_exists($key, $this->files)) {
            return $default;
        }

        return $this->normalizeUploadedFileValue($this->files[$key]);
    }

    /**
     * Return a raw PHP `$_FILES` entry or the full files array.
     *
     * @return array<string, mixed>|mixed
     */
    public function rawFile(?string $key = null, mixed $default = null): mixed
    {
        return $this->valueFrom($this->files, $key, $default);
    }

    /**
     * Return a server value or the full server array.
     *
     * @return array<string, mixed>|mixed
     */
    public function server(?string $key = null, mixed $default = null): mixed
    {
        return $this->valueFrom($this->server, $key, $default);
    }

    /**
     * Return a header value or the full normalized header map.
     *
     * @return array<string, string>|string|mixed
     */
    public function header(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->headers();
        }

        return $this->headers[$this->normalizeHeaderLookupName($name)] ?? $default;
    }

    /**
     * Return all headers with formatted header names.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $headers[$this->formatHeaderName($name)] = $value;
        }

        return $headers;
    }

    /**
     * Return the resolved request scheme.
     */
    public function scheme(): string
    {
        return $this->scheme;
    }

    /**
     * Determine whether the request uses HTTPS.
     */
    public function secure(): bool
    {
        return $this->scheme === 'https';
    }

    /**
     * Return the resolved request host.
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * Return the resolved request port.
     */
    public function port(): ?int
    {
        return $this->port;
    }

    /**
     * Return the request path without the query string.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Return the raw request URI.
     */
    public function requestUri(): string
    {
        return $this->requestUri;
    }

    /**
     * Return the request query string.
     */
    public function queryString(): string
    {
        return $this->queryString;
    }

    /**
     * Return the request URL without the query string.
     */
    public function url(): string
    {
        return sprintf('%s://%s%s', $this->scheme, $this->authority(), $this->path);
    }

    /**
     * Return the full request URL including the query string.
     */
    public function fullUrl(): string
    {
        if ($this->queryString === '') {
            return $this->url();
        }

        return sprintf('%s?%s', $this->url(), $this->queryString);
    }

    /**
     * Determine whether the request was made through XMLHttpRequest.
     */
    public function ajax(): bool
    {
        return strtolower((string) $this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * Return the bearer token from the Authorization header when present.
     */
    public function bearerToken(): ?string
    {
        $header = trim((string) $this->header('Authorization', ''));
        if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }

    /**
     * Determine whether the client expects a JSON response.
     */
    public function expectsJson(): bool
    {
        $accept = strtolower((string) $this->header('Accept', ''));
        if (str_contains($accept, 'application/json') || str_contains($accept, '+json')) {
            return true;
        }

        $contentType = strtolower((string) $this->header('Content-Type', ''));
        if (str_contains($contentType, 'application/json') || str_contains($contentType, '+json')) {
            return true;
        }

        return $this->ajax()
            || $this->path === '/api'
            || str_starts_with($this->path, '/api/');
    }

    /**
     * Return the client IP address when available.
     */
    public function ip(): ?string
    {
        $address = trim((string) ($this->server['REMOTE_ADDR'] ?? ''));

        return $address === '' ? null : $address;
    }

    /**
     * Return the raw request body content.
     */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>|mixed
     */
    private function valueFrom(array $values, ?string $key, mixed $default): mixed
    {
        if ($key === null) {
            return $values;
        }

        return $values[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private function normalizeUploadedFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            $normalized[$key] = $this->normalizeUploadedFileValue($value);
        }

        return $normalized;
    }

    private function normalizeUploadedFileValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isUploadedFileArray($value)) {
            if ($this->hasNestedUploadedFiles($value)) {
                return $this->normalizeNestedUploadedFiles($value);
            }

            return UploadedFile::fromArray($value);
        }

        $normalized = [];

        foreach ($value as $key => $nestedValue) {
            $normalized[$key] = $this->normalizeUploadedFileValue($nestedValue);
        }

        return $normalized;
    }

    /**
     * @param array{name: mixed, type: mixed, size: mixed, tmp_name: mixed, error: mixed} $fileData
     * @return array<int|string, mixed>
     */
    private function normalizeNestedUploadedFiles(array $fileData): array
    {
        $keys = [];

        foreach (['name', 'type', 'size', 'tmp_name', 'error'] as $attribute) {
            if (!is_array($fileData[$attribute])) {
                continue;
            }

            foreach (array_keys($fileData[$attribute]) as $key) {
                $keys[$key] = true;
            }
        }

        $normalized = [];

        foreach (array_keys($keys) as $key) {
            $normalized[$key] = $this->normalizeUploadedFileValue([
                'name' => is_array($fileData['name']) ? ($fileData['name'][$key] ?? null) : null,
                'type' => is_array($fileData['type']) ? ($fileData['type'][$key] ?? null) : null,
                'size' => is_array($fileData['size']) ? ($fileData['size'][$key] ?? null) : null,
                'tmp_name' => is_array($fileData['tmp_name']) ? ($fileData['tmp_name'][$key] ?? null) : null,
                'error' => is_array($fileData['error']) ? ($fileData['error'][$key] ?? null) : null,
            ]);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function isUploadedFileArray(array $value): bool
    {
        $requiredKeys = ['error', 'name', 'size', 'tmp_name', 'type'];

        sort($requiredKeys);
        $keys = array_keys($value);
        sort($keys);

        return $keys === $requiredKeys;
    }

    /**
     * @param array{name: mixed, type: mixed, size: mixed, tmp_name: mixed, error: mixed} $value
     */
    private function hasNestedUploadedFiles(array $value): bool
    {
        return is_array($value['name'])
            || is_array($value['type'])
            || is_array($value['size'])
            || is_array($value['tmp_name'])
            || is_array($value['error']);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function normalizeRequestUri(string $requestUri, array $query): string
    {
        $requestUri = trim($requestUri);
        if ($requestUri === '') {
            $requestUri = '/';
        }

        if (!str_starts_with($requestUri, '/')) {
            $parsedPath = parse_url($requestUri, \PHP_URL_PATH);
            $parsedQuery = parse_url($requestUri, \PHP_URL_QUERY);

            $requestUri = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
            if (is_string($parsedQuery) && $parsedQuery !== '') {
                $requestUri .= sprintf('?%s', $parsedQuery);
            }
        }

        if (str_contains($requestUri, '?') || $query === []) {
            return $requestUri;
        }

        $queryString = http_build_query($query);

        return $queryString === '' ? $requestUri : sprintf('%s?%s', $requestUri, $queryString);
    }

    private function resolvePath(string $requestUri): string
    {
        $path = parse_url($requestUri, \PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        return str_starts_with($path, '/') ? $path : sprintf('/%s', $path);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     */
    private function resolveQueryString(array $server, string $requestUri, array $query): string
    {
        $queryString = trim((string) ($server['QUERY_STRING'] ?? ''));
        if ($queryString !== '') {
            return $queryString;
        }

        $uriQuery = parse_url($requestUri, \PHP_URL_QUERY);
        if (is_string($uriQuery) && $uriQuery !== '') {
            return $uriQuery;
        }

        return http_build_query($query);
    }

    /**
     * @param array<string, mixed> $server
     */
    private function resolveScheme(array $server): string
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return 'https';
        }

        $forwardedProto = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto !== '') {
            return trim(explode(',', $forwardedProto)[0]);
        }

        $requestScheme = strtolower((string) ($server['REQUEST_SCHEME'] ?? ''));

        return $requestScheme !== '' ? $requestScheme : 'http';
    }

    /**
     * @param array<string, mixed> $server
     */
    private function resolveHost(array $server): string
    {
        $host = trim((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost'));
        if ($host === '') {
            return 'localhost';
        }

        if (str_starts_with($host, '[')) {
            $position = strpos($host, ']');

            return $position === false ? $host : substr($host, 0, $position + 1);
        }

        return explode(':', $host, 2)[0];
    }

    /**
     * @param array<string, mixed> $server
     */
    private function resolvePort(array $server, string $scheme): ?int
    {
        $host = trim((string) ($server['HTTP_HOST'] ?? ''));
        if ($host !== '' && str_starts_with($host, '[')) {
            $position = strrpos($host, ']:');
            if ($position !== false) {
                return $this->toPort(substr($host, $position + 2), $scheme);
            }
        }

        if ($host !== '' && !str_starts_with($host, '[') && str_contains($host, ':')) {
            return $this->toPort(explode(':', $host, 2)[1], $scheme);
        }

        $serverPort = $this->toPort((string) ($server['SERVER_PORT'] ?? ''), $scheme);
        if ($serverPort !== null) {
            return $serverPort;
        }

        return $scheme === 'https' ? 443 : 80;
    }

    private function authority(): string
    {
        if ($this->port === null || $this->isDefaultPort($this->scheme, $this->port)) {
            return $this->host;
        }

        return sprintf('%s:%d', $this->host, $this->port);
    }

    private function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }

    private function normalizeMethod(string $method): string
    {
        $normalized = strtoupper(trim($method));

        return $normalized === '' ? 'GET' : $normalized;
    }

    private function normalizeHeaderLookupName(string $name): string
    {
        return strtolower(str_replace('_', '-', trim($name)));
    }

    private function formatHeaderName(string $name): string
    {
        return implode('-', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            explode('-', $this->normalizeHeaderLookupName($name)),
        ));
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headerName = substr($key, 5);
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $headerName = $key;
            } else {
                continue;
            }

            $headers[$this->normalizeHeaderLookupName($headerName)] = (string) $value;
        }

        ksort($headers);

        return $headers;
    }

    private function toPort(string $port, string $scheme): ?int
    {
        $port = trim($port);
        if ($port === '') {
            return null;
        }

        if (!ctype_digit($port)) {
            return $scheme === 'https' ? 443 : 80;
        }

        $resolvedPort = (int) $port;

        return $resolvedPort > 0 ? $resolvedPort : null;
    }
}
