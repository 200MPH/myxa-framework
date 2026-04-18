<?php

declare(strict_types=1);

namespace Myxa\Storage\S3;

use Closure;
use InvalidArgumentException;
use JsonException;
use Myxa\Storage\AbstractStorage;
use Myxa\Storage\Exceptions\StorageException;
use Myxa\Storage\StoredFile;

final class S3Storage extends AbstractStorage
{
    private const string HEADER_NAME = 'x-amz-meta-myxa-name';
    private const string HEADER_CHECKSUM = 'x-amz-meta-myxa-checksum-sha1';
    private const string HEADER_METADATA = 'x-amz-meta-myxa-metadata';

    private string $bucket;

    private string $region;

    private string $accessKey;

    private string $secretKey;

    private ?string $sessionToken;

    private ?string $endpoint;

    private bool $pathStyle;

    private ?Closure $transport;

    public function __construct(
        string $bucket,
        string $region,
        string $accessKey,
        string $secretKey,
        ?string $sessionToken = null,
        ?string $endpoint = null,
        bool $pathStyle = false,
        string $alias = 's3',
        ?callable $transport = null,
    ) {
        parent::__construct($alias);

        $this->bucket = $this->normalizeRequiredValue($bucket, 'S3 bucket');
        $this->region = $this->normalizeRequiredValue($region, 'S3 region');
        $this->accessKey = $this->normalizeRequiredValue($accessKey, 'S3 access key');
        $this->secretKey = $this->normalizeRequiredValue($secretKey, 'S3 secret key');
        $this->sessionToken = $sessionToken;
        $this->endpoint = $this->normalizeOptionalEndpoint($endpoint);
        $this->pathStyle = $pathStyle;
        $this->transport = $transport !== null ? Closure::fromCallable($transport) : null;
    }

    /**
     * Persist contents to S3-compatible object storage.
     *
     * @param array{name?: string, mime_type?: string, metadata?: array<string, mixed>} $options
     */
    public function put(string $location, string $contents, array $options = []): StoredFile
    {
        $location = $this->normalizeLocation($location);
        $name = $this->resolveName($location, $options);
        $mimeType = $this->resolveMimeType($options);
        $checksum = sha1($contents);
        $metadata = $this->resolveMetadata($options);

        $headers = [
            self::HEADER_NAME => $name,
            self::HEADER_CHECKSUM => $checksum,
        ];

        if ($mimeType !== null) {
            $headers['Content-Type'] = $mimeType;
        }

        if ($metadata !== []) {
            $headers[self::HEADER_METADATA] = base64_encode($this->encodeMetadata($metadata));
        }

        $response = $this->request('PUT', $location, $contents, $headers);

        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw $this->requestFailed('write', $location, $response);
        }

        return new StoredFile(
            $this->alias(),
            $location,
            $name,
            strlen($contents),
            $mimeType,
            $checksum,
            $metadata + $this->responseMetadata($location, $response),
        );
    }

    public function get(string $location): ?StoredFile
    {
        $location = $this->normalizeLocation($location);
        $response = $this->request('HEAD', $location);

        if ($response->statusCode() === 404) {
            return null;
        }

        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw $this->requestFailed('load metadata for', $location, $response);
        }

        return $this->storedFileFromResponse($location, $response);
    }

    public function read(string $location): string
    {
        $location = $this->normalizeLocation($location);
        $response = $this->request('GET', $location);

        if ($response->statusCode() === 404) {
            throw new StorageException(sprintf('File "%s" does not exist in "%s" storage.', $location, $this->alias()));
        }

        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw $this->requestFailed('read', $location, $response);
        }

        return $response->body();
    }

    public function delete(string $location): bool
    {
        $location = $this->normalizeLocation($location);

        if (!$this->exists($location)) {
            return false;
        }

        $response = $this->request('DELETE', $location);

        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw $this->requestFailed('delete', $location, $response);
        }

        return true;
    }

    public function exists(string $location): bool
    {
        $location = $this->normalizeLocation($location);
        $response = $this->request('HEAD', $location);

        return match (true) {
            $response->statusCode() === 404 => false,
            $response->statusCode() >= 200 && $response->statusCode() < 300 => true,
            default => throw $this->requestFailed('inspect', $location, $response),
        };
    }

    private function storedFileFromResponse(string $location, S3Response $response): StoredFile
    {
        $name = $response->header(self::HEADER_NAME) ?? basename($location);
        $size = (int) ($response->header('content-length') ?? 0);
        $mimeType = $response->header('content-type');
        $checksum = $response->header(self::HEADER_CHECKSUM);
        $metadata = $this->decodeMetadata($response->header(self::HEADER_METADATA));

        return new StoredFile(
            $this->alias(),
            $location,
            $name,
            $size,
            $mimeType !== '' ? $mimeType : null,
            $checksum !== '' ? $checksum : null,
            $metadata + $this->responseMetadata($location, $response),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function responseMetadata(string $location, S3Response $response): array
    {
        $metadata = [
            'bucket' => $this->bucket,
            'key' => $location,
            'region' => $this->region,
        ];

        $etag = $this->normalizeEtag($response->header('etag'));
        if ($etag !== null) {
            $metadata['etag'] = $etag;
        }

        $versionId = $response->header('x-amz-version-id');
        if ($versionId !== null && $versionId !== '') {
            $metadata['version_id'] = $versionId;
        }

        $lastModified = $response->header('last-modified');
        if ($lastModified !== null && $lastModified !== '') {
            $metadata['last_modified'] = $lastModified;
        }

        $urlData = $this->buildObjectUrl($location);
        $metadata['url'] = $urlData['url'];

        return $metadata;
    }

    private function request(string $method, string $location, string $body = '', array $headers = []): S3Response
    {
        $urlData = $this->buildObjectUrl($location);
        $payloadHash = hash('sha256', $body);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);
        $headers = $this->signHeaders(
            $method,
            $urlData['host'],
            $urlData['path'],
            $payloadHash,
            $timestamp,
            $date,
            $headers,
        );

        return $this->send($method, $urlData['url'], $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function signHeaders(
        string $method,
        string $host,
        string $path,
        string $payloadHash,
        string $timestamp,
        string $date,
        array $headers,
    ): array {
        $headers['Host'] = $host;
        $headers['x-amz-content-sha256'] = $payloadHash;
        $headers['x-amz-date'] = $timestamp;

        if ($this->sessionToken !== null && trim($this->sessionToken) !== '') {
            $headers['x-amz-security-token'] = trim($this->sessionToken);
        }

        $canonicalHeaders = [];

        foreach ($headers as $name => $value) {
            $normalizedName = strtolower(trim($name));
            $normalizedValue = preg_replace('/\s+/', ' ', trim((string) $value));
            $canonicalHeaders[$normalizedName] = $normalizedValue ?? trim((string) $value);
        }

        ksort($canonicalHeaders);

        $signedHeaders = implode(';', array_keys($canonicalHeaders));
        $canonicalHeaderBlock = '';

        foreach ($canonicalHeaders as $name => $value) {
            $canonicalHeaderBlock .= $name . ':' . $value . "\n";
        }

        $canonicalRequest = strtoupper($method) . "\n"
            . $path . "\n"
            . "\n"
            . $canonicalHeaderBlock
            . $signedHeaders . "\n"
            . $payloadHash;

        $credentialScope = sprintf('%s/%s/s3/aws4_request', $date, $this->region);
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));
        $headers['Authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $credentialScope,
            $signedHeaders,
            $signature,
        );

        return $headers;
    }

    private function signingKey(string $date): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    /**
     * @return array{host: string, path: string, url: string}
     */
    private function buildObjectUrl(string $location): array
    {
        $encodedBucket = rawurlencode($this->bucket);
        $encodedKey = $this->encodePath($location);

        if ($this->endpoint === null) {
            $host = $this->pathStyle
                ? sprintf('s3.%s.amazonaws.com', $this->region)
                : sprintf('%s.s3.%s.amazonaws.com', $this->bucket, $this->region);
            $path = $this->pathStyle ? sprintf('/%s/%s', $encodedBucket, $encodedKey) : '/' . $encodedKey;

            return [
                'host' => $host,
                'path' => $path,
                'url' => 'https://' . $host . $path,
            ];
        }

        $parts = parse_url($this->endpoint);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('S3 endpoint must be a valid absolute URL.');
        }

        $basePath = isset($parts['path']) ? trim($parts['path'], '/') : '';
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if ($this->pathStyle) {
            $path = $this->joinPath($basePath, $encodedBucket, $encodedKey);

            return [
                'host' => $host . $port,
                'path' => $path,
                'url' => sprintf('%s://%s%s%s', $scheme, $host, $port, $path),
            ];
        }

        $path = $this->joinPath($basePath, $encodedKey);

        return [
            'host' => $this->bucket . '.' . $host . $port,
            'path' => $path,
            'url' => sprintf('%s://%s.%s%s%s', $scheme, $this->bucket, $host, $port, $path),
        ];
    }

    private function encodePath(string $location): string
    {
        $segments = explode('/', $location);

        return implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), $segments));
    }

    private function joinPath(string ...$segments): string
    {
        $filtered = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));

        return '/' . implode('/', $filtered);
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $method, string $url, string $body, array $headers): S3Response
    {
        if ($this->transport instanceof Closure) {
            $response = ($this->transport)($method, $url, $body, $headers);

            if (!$response instanceof S3Response) {
                throw new StorageException(sprintf(
                    'S3 transport for "%s" storage must return %s.',
                    $this->alias(),
                    S3Response::class,
                ));
            }

            return $response;
        }

        return function_exists('curl_init')
            ? $this->sendWithCurl($method, $url, $body, $headers)
            : $this->sendWithStreams($method, $url, $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function sendWithCurl(string $method, string $url, string $body, array $headers): S3Response
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new StorageException(sprintf('Unable to initialize S3 request for "%s".', $url));
        }

        $responseHeaders = [];
        $requestHeaders = [];

        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $trimmed = trim($header);

                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return strlen($header);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[$name] = trim($value);

                return strlen($header);
            },
        ]);

        if ($method === 'PUT') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($responseBody === false) {
            $message = curl_error($handle);
            curl_close($handle);

            throw new StorageException(sprintf('S3 request to "%s" failed: %s', $url, $message));
        }

        curl_close($handle);

        return new S3Response($statusCode, $responseHeaders, $responseBody);
    }

    /**
     * @param array<string, string> $headers
     * @codeCoverageIgnore
     */
    private function sendWithStreams(string $method, string $url, string $body, array $headers): S3Response
    {
        $requestHeaders = [];

        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $requestHeaders),
                'content' => $method === 'PUT' ? $body : '',
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];

        if ($responseBody === false && $responseHeaders === []) {
            throw new StorageException(sprintf('S3 request to "%s" failed.', $url));
        }

        $statusCode = 0;
        $normalizedHeaders = [];

        foreach ($responseHeaders as $index => $headerLine) {
            if ($index === 0) {
                preg_match('/\s(\d{3})\s/', $headerLine, $matches);
                $statusCode = isset($matches[1]) ? (int) $matches[1] : 0;

                continue;
            }

            if (!str_contains($headerLine, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $headerLine, 2);
            $normalizedHeaders[$name] = trim($value);
        }

        return new S3Response($statusCode, $normalizedHeaders, is_string($responseBody) ? $responseBody : '');
    }

    private function requestFailed(string $action, string $location, S3Response $response): StorageException
    {
        $details = trim($response->body());

        if ($details !== '') {
            return new StorageException(sprintf(
                'Unable to %s file "%s" in "%s" storage. S3 responded with HTTP %d: %s',
                $action,
                $location,
                $this->alias(),
                $response->statusCode(),
                $details,
            ));
        }

        return new StorageException(sprintf(
            'Unable to %s file "%s" in "%s" storage. S3 responded with HTTP %d.',
            $action,
            $location,
            $this->alias(),
            $response->statusCode(),
        ));
    }

    private function normalizeRequiredValue(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s cannot be empty.', $label));
        }

        return $value;
    }

    private function normalizeOptionalEndpoint(?string $endpoint): ?string
    {
        if ($endpoint === null) {
            return null;
        }

        $endpoint = trim($endpoint);

        return $endpoint === '' ? null : rtrim($endpoint, '/');
    }

    private function normalizeEtag(?string $etag): ?string
    {
        if ($etag === null) {
            return null;
        }

        $etag = trim($etag, "\" \t\n\r\0\x0B");

        return $etag === '' ? null : $etag;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function encodeMetadata(array $metadata): string
    {
        try {
            return json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stored file metadata must be JSON serializable.', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(?string $encoded): array
    {
        if ($encoded === null || trim($encoded) === '') {
            return [];
        }

        $json = base64_decode($encoded, true);

        if (!is_string($json) || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
