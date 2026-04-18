<?php

declare(strict_types=1);

namespace Test\Unit\Support\Storage;

use InvalidArgumentException;
use Myxa\Storage\Exceptions\StorageException;
use Myxa\Storage\S3\S3Response;
use Myxa\Storage\S3\S3Storage;
use Myxa\Storage\StoredFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(S3Storage::class)]
#[CoversClass(S3Response::class)]
#[CoversClass(StoredFile::class)]
#[CoversClass(StorageException::class)]
final class S3StorageTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }

            if (is_dir($file)) {
                $this->deleteDirectory($file);
            }
        }
    }

    public function testS3StoragePersistsReadsAndDeletesFiles(): void
    {
        $transport = new FakeS3Transport();
        $storage = new S3Storage(
            bucket: 'myxa-bucket',
            region: 'eu-central-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            transport: $transport,
        );

        $stored = $storage->put(
            'avatars/user-1.txt',
            'hello world',
            ['mime_type' => 'text/plain', 'metadata' => ['owner' => 'user-1']],
        );

        self::assertSame('s3', $stored->storage());
        self::assertSame('avatars/user-1.txt', $stored->location());
        self::assertSame('user-1.txt', $stored->name());
        self::assertSame('txt', $stored->extension());
        self::assertSame(11, $stored->size());
        self::assertSame('text/plain', $stored->mimeType());
        self::assertSame(sha1('hello world'), $stored->checksum());
        self::assertSame('user-1', $stored->metadata('owner'));
        self::assertSame('myxa-bucket', $stored->metadata('bucket'));
        self::assertSame('avatars/user-1.txt', $stored->metadata('key'));
        self::assertSame('etag-1', $stored->metadata('etag'));
        self::assertTrue($storage->exists('avatars/user-1.txt'));
        self::assertSame('hello world', $storage->read('avatars/user-1.txt'));

        $resolved = $storage->get('avatars/user-1.txt');

        self::assertInstanceOf(StoredFile::class, $resolved);
        self::assertSame('avatars/user-1.txt', $resolved->location());
        self::assertSame('user-1.txt', $resolved->name());
        self::assertSame('user-1', $resolved->metadata('owner'));
        self::assertSame('ver-1', $resolved->metadata('version_id'));
        self::assertTrue($storage->delete('avatars/user-1.txt'));
        self::assertFalse($storage->exists('avatars/user-1.txt'));
        self::assertNull($storage->get('avatars/user-1.txt'));

        self::assertSame('PUT', $transport->requests()[0]['method']);
        self::assertStringContainsString('Authorization', implode('|', array_keys($transport->requests()[0]['headers'])));
        self::assertSame(hash('sha256', 'hello world'), $transport->requests()[0]['headers']['x-amz-content-sha256']);
        self::assertSame('user-1.txt', $transport->requests()[0]['headers']['x-amz-meta-myxa-name']);
        self::assertSame(
            sha1('hello world'),
            $transport->requests()[0]['headers']['x-amz-meta-myxa-checksum-sha1'],
        );
    }

    public function testS3StorageSupportsCustomEndpointAndPathStyleUrls(): void
    {
        $transport = new FakeS3Transport();
        $storage = new S3Storage(
            bucket: 'uploads',
            region: 'us-east-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            endpoint: 'https://storage.example.test/root',
            pathStyle: true,
            transport: $transport,
        );

        $storage->put('docs/report.txt', 'draft');

        self::assertSame(
            'https://storage.example.test/root/uploads/docs/report.txt',
            $transport->requests()[0]['url'],
        );
        self::assertSame('storage.example.test', $transport->requests()[0]['headers']['Host']);
    }

    public function testS3StorageSupportsVirtualHostedCustomEndpointAndSessionToken(): void
    {
        $transport = new FakeS3Transport();
        $storage = new S3Storage(
            bucket: 'uploads',
            region: 'us-east-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            sessionToken: 'session-token',
            endpoint: 'https://storage.example.test/root/',
            transport: $transport,
        );

        $storage->put('docs/report.txt', 'draft');

        self::assertSame(
            'https://uploads.storage.example.test/root/docs/report.txt',
            $transport->requests()[0]['url'],
        );
        self::assertSame('uploads.storage.example.test', $transport->requests()[0]['headers']['Host']);
        self::assertSame('session-token', $transport->requests()[0]['headers']['x-amz-security-token']);
    }

    public function testS3StorageReturnsClearMissingFileBehavior(): void
    {
        $storage = new S3Storage(
            bucket: 'myxa-bucket',
            region: 'eu-central-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            transport: new FakeS3Transport(),
        );

        self::assertFalse($storage->exists('missing.txt'));
        self::assertFalse($storage->delete('missing.txt'));
        self::assertNull($storage->get('missing.txt'));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('File "missing.txt" does not exist in "s3" storage.');

        $storage->read('missing.txt');
    }

    public function testS3StorageUsesDefaultCurlTransportAgainstLocalHttpServer(): void
    {
        $server = LocalS3TestServer::start($this);

        try {
            $storage = new S3Storage(
                bucket: 'uploads',
                region: 'eu-central-1',
                accessKey: 'access-key',
                secretKey: 'secret-key',
                endpoint: $server->endpoint(),
                pathStyle: true,
                alias: 'assets',
            );

            $stored = $storage->put(
                'docs/report.txt',
                'curl body',
                ['mime_type' => 'text/plain', 'metadata' => ['owner' => 'qa']],
            );

            self::assertSame('assets', $stored->storage());
            self::assertSame('qa', $stored->metadata('owner'));
            self::assertSame('uploads', $stored->metadata('bucket'));
            self::assertStringStartsWith('text/plain', $storage->get('docs/report.txt')?->mimeType() ?? '');
            self::assertSame('curl body', $storage->read('docs/report.txt'));
            self::assertTrue($storage->exists('docs/report.txt'));
            self::assertTrue($storage->delete('docs/report.txt'));
            self::assertFalse($storage->exists('docs/report.txt'));
        } finally {
            $server->stop();
        }
    }

    public function testS3StorageSurfacesHttpErrorsAndMalformedMetadata(): void
    {
        $errorStorage = new S3Storage(
            bucket: 'myxa-bucket',
            region: 'eu-central-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            transport: static fn (string $method, string $url, string $body, array $headers): S3Response => new S3Response(500, body: 'boom'),
        );

        try {
            $errorStorage->put('docs/test.txt', 'payload');
            self::fail('Expected write failure.');
        } catch (StorageException $exception) {
            self::assertSame(
                'Unable to write file "docs/test.txt" in "s3" storage. S3 responded with HTTP 500: boom',
                $exception->getMessage(),
            );
        }

        $inspectStorage = new S3Storage(
            bucket: 'myxa-bucket',
            region: 'eu-central-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            transport: static fn (string $method, string $url, string $body, array $headers): S3Response => new S3Response(403),
        );

        try {
            $inspectStorage->exists('docs/test.txt');
            self::fail('Expected exists failure.');
        } catch (StorageException $exception) {
            self::assertSame(
                'Unable to inspect file "docs/test.txt" in "s3" storage. S3 responded with HTTP 403.',
                $exception->getMessage(),
            );
        }

        $metadataStorage = new S3Storage(
            bucket: 'myxa-bucket',
            region: 'eu-central-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            transport: static fn (string $method, string $url, string $body, array $headers): S3Response => new S3Response(200, [
                'Content-Length' => '12',
                'Content-Type' => 'text/plain',
                'ETag' => "\" \t\"",
                'x-amz-meta-myxa-metadata' => 'not-base64',
            ]),
        );

        $stored = $metadataStorage->get('docs/test.txt');

        self::assertInstanceOf(StoredFile::class, $stored);
        self::assertNull($stored->checksum());
        self::assertNull($stored->metadata('etag'));
        self::assertSame('myxa-bucket', $stored->metadata('bucket'));
        self::assertNull($stored->metadata('owner'));
    }

    public function testS3StorageRejectsInvalidEndpointAndInvalidTransportResponses(): void
    {
        $invalidEndpointStorage = new S3Storage(
            bucket: 'myxa-bucket',
            region: 'eu-central-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            endpoint: 'not-a-valid-url',
            transport: new FakeS3Transport(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('S3 endpoint must be a valid absolute URL.');

        $invalidEndpointStorage->get('docs/test.txt');
    }

    public function testS3StorageRejectsTransportThatReturnsWrongType(): void
    {
        $storage = new S3Storage(
            bucket: 'myxa-bucket',
            region: 'eu-central-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            transport: static fn (string $method, string $url, string $body, array $headers): \stdClass => new \stdClass(),
        );

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage(
            'S3 transport for "s3" storage must return Myxa\\Storage\\S3\\S3Response.',
        );

        $storage->get('docs/test.txt');
    }

    public function testS3StorageReportsCurlFailures(): void
    {
        $storage = new S3Storage(
            bucket: 'uploads',
            region: 'eu-central-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            endpoint: 'http://127.0.0.1:1',
            pathStyle: true,
        );

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('S3 request to "http://127.0.0.1:1/uploads/docs/test.txt" failed');

        $storage->get('docs/test.txt');
    }

    public function testS3StorageValidatesConstructorAndMetadataValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('S3 bucket cannot be empty.');

        new S3Storage(
            bucket: ' ',
            region: 'eu-central-1',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            transport: new FakeS3Transport(),
        );
    }

    public function testS3StorageRejectsNonJsonSerializableMetadata(): void
    {
        $resource = fopen('php://temp', 'rb');
        if (!is_resource($resource)) {
            self::fail('Unable to open temporary resource.');
        }

        try {
            $storage = new S3Storage(
                bucket: 'myxa-bucket',
                region: 'eu-central-1',
                accessKey: 'access-key',
                secretKey: 'secret-key',
                transport: new FakeS3Transport(),
            );

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Stored file metadata must be JSON serializable.');

            $storage->put('docs/test.txt', 'payload', ['metadata' => ['stream' => $resource]]);
        } finally {
            fclose($resource);
        }
    }

    public function testS3ResponseNormalizesHeaders(): void
    {
        $response = new S3Response(201, ['Content-Type' => 'text/plain', ' X-Test ' => ' ok '], 'body');

        self::assertSame(201, $response->statusCode());
        self::assertSame('body', $response->body());
        self::assertSame([
            'content-type' => 'text/plain',
            'x-test' => 'ok',
        ], $response->headers());
        self::assertSame('ok', $response->header('X-Test'));
    }

    public function rememberTempPath(string $path): string
    {
        $this->tempFiles[] = $path;

        return $path;
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}

final class FakeS3Transport
{
    /**
     * @var array<string, array{body: string, headers: array<string, string>}>
     */
    private array $objects = [];

    /**
     * @var list<array{method: string, url: string, body: string, headers: array<string, string>}>
     */
    private array $requests = [];

    public function __invoke(string $method, string $url, string $body, array $headers): S3Response
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'body' => $body,
            'headers' => $headers,
        ];

        return match ($method) {
            'PUT' => $this->put($url, $body, $headers),
            'HEAD' => $this->head($url),
            'GET' => $this->get($url),
            'DELETE' => $this->delete($url),
            default => new S3Response(500, body: 'unsupported method'),
        };
    }

    /**
     * @return list<array{method: string, url: string, body: string, headers: array<string, string>}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @param array<string, string> $headers
     */
    private function put(string $url, string $body, array $headers): S3Response
    {
        $normalizedHeaders = [];

        foreach ($headers as $name => $value) {
            $normalizedHeaders[strtolower($name)] = $value;
        }

        $this->objects[$url] = [
            'body' => $body,
            'headers' => [
                'content-length' => (string) strlen($body),
                'content-type' => $normalizedHeaders['content-type'] ?? 'application/octet-stream',
                'etag' => '"etag-1"',
                'x-amz-version-id' => 'ver-1',
                'last-modified' => 'Sat, 18 Apr 2026 12:00:00 GMT',
                'x-amz-meta-myxa-name' => $normalizedHeaders['x-amz-meta-myxa-name'] ?? basename(parse_url($url, PHP_URL_PATH) ?? ''),
                'x-amz-meta-myxa-checksum-sha1' => $normalizedHeaders['x-amz-meta-myxa-checksum-sha1'] ?? '',
                'x-amz-meta-myxa-metadata' => $normalizedHeaders['x-amz-meta-myxa-metadata'] ?? '',
            ],
        ];

        return new S3Response(200, [
            'etag' => '"etag-1"',
            'x-amz-version-id' => 'ver-1',
        ]);
    }

    private function head(string $url): S3Response
    {
        if (!isset($this->objects[$url])) {
            return new S3Response(404);
        }

        return new S3Response(200, $this->objects[$url]['headers']);
    }

    private function get(string $url): S3Response
    {
        if (!isset($this->objects[$url])) {
            return new S3Response(404);
        }

        return new S3Response(200, $this->objects[$url]['headers'], $this->objects[$url]['body']);
    }

    private function delete(string $url): S3Response
    {
        if (!isset($this->objects[$url])) {
            return new S3Response(404);
        }

        unset($this->objects[$url]);

        return new S3Response(204);
    }
}

final class LocalS3TestServer
{
    private function __construct(
        private readonly TestCase $testCase,
        private readonly string $endpoint,
        private readonly string $router,
        private readonly string $dataRoot,
        /** @var resource */
        private readonly mixed $process,
        /** @var array<int, resource> */
        private readonly array $pipes,
    ) {
    }

    public static function start(S3StorageTest $testCase): self
    {
        $dataRoot = $testCase->rememberTempPath(sys_get_temp_dir() . '/myxa-s3-http-' . bin2hex(random_bytes(6)));
        if (!mkdir($dataRoot, 0777, true) && !is_dir($dataRoot)) {
            $testCase->fail('Unable to create local S3 test data directory.');
        }

        $router = tempnam(sys_get_temp_dir(), 'myxa-s3-router-');
        if (!is_string($router)) {
            $testCase->fail('Unable to create local S3 router script.');
        }

        $router = $testCase->rememberTempPath($router);
        file_put_contents($router, self::routerScript($dataRoot));

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            $testCase->fail(sprintf('Unable to reserve HTTP port: %s', $errorMessage));
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($address) || !str_contains($address, ':')) {
            $testCase->fail('Unable to resolve HTTP test server address.');
        }

        [, $port] = explode(':', $address, 2);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            sprintf('php -S 127.0.0.1:%s %s', $port, escapeshellarg($router)),
            $descriptorSpec,
            $pipes,
        );

        if (!is_resource($process)) {
            $testCase->fail('Unable to start local HTTP server.');
        }

        $server = new self(
            $testCase,
            sprintf('http://127.0.0.1:%s', $port),
            $router,
            $dataRoot,
            $process,
            $pipes,
        );

        $server->waitUntilReady();

        return $server;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private function waitUntilReady(): void
    {
        $maxAttempts = 50;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $connection = @fsockopen('127.0.0.1', (int) parse_url($this->endpoint, PHP_URL_PORT), $errorCode, $errorMessage, 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(20000);
        }

        $this->testCase->fail('Local HTTP server did not start in time.');
    }

    private static function routerScript(string $dataRoot): string
    {
        $exportedRoot = var_export($dataRoot, true);

        return <<<PHP
<?php
declare(strict_types=1);

\$root = {$exportedRoot};
\$uri = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
\$fileKey = sha1(\$uri);
\$bodyFile = \$root . '/' . \$fileKey . '.body';
\$metaFile = \$root . '/' . \$fileKey . '.json';
\$method = \$_SERVER['REQUEST_METHOD'] ?? 'GET';
\$headers = [];

foreach (\$_SERVER as \$name => \$value) {
    if (!is_string(\$value) || !str_starts_with(\$name, 'HTTP_')) {
        continue;
    }

    \$headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr(\$name, 5)))));
    \$headers[strtolower(\$headerName)] = \$value;
}

if (\$method === 'PUT') {
    file_put_contents(\$bodyFile, file_get_contents('php://input') ?: '');
    file_put_contents(\$metaFile, json_encode([
        'content-type' => \$headers['content-type'] ?? 'application/octet-stream',
        'content-length' => (string) filesize(\$bodyFile),
        'etag' => '"curl-etag"',
        'x-amz-version-id' => 'curl-version',
        'last-modified' => 'Sat, 18 Apr 2026 12:00:00 GMT',
        'x-amz-meta-myxa-name' => \$headers['x-amz-meta-myxa-name'] ?? basename(\$uri),
        'x-amz-meta-myxa-checksum-sha1' => \$headers['x-amz-meta-myxa-checksum-sha1'] ?? '',
        'x-amz-meta-myxa-metadata' => \$headers['x-amz-meta-myxa-metadata'] ?? '',
    ], JSON_THROW_ON_ERROR));

    header('ETag: "curl-etag"');
    header('x-amz-version-id: curl-version');
    http_response_code(200);
    return true;
}

if (!is_file(\$metaFile)) {
    http_response_code(404);
    return true;
}

\$metadata = json_decode(file_get_contents(\$metaFile) ?: '{}', true, 512, JSON_THROW_ON_ERROR);

foreach (\$metadata as \$name => \$value) {
    header(\$name . ': ' . \$value);
}

if (\$method === 'HEAD') {
    http_response_code(200);
    return true;
}

if (\$method === 'GET') {
    http_response_code(200);
    echo file_get_contents(\$bodyFile) ?: '';
    return true;
}

if (\$method === 'DELETE') {
    @unlink(\$bodyFile);
    @unlink(\$metaFile);
    http_response_code(204);
    return true;
}

http_response_code(405);
return true;
PHP;
    }
}
