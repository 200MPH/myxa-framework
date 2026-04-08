<?php

declare(strict_types=1);

namespace Test\Unit\Support\Storage;

use Myxa\Storage\Local\LocalStorage;
use Myxa\Storage\Exceptions\StorageException;
use Myxa\Storage\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UploadedFile::class)]
#[CoversClass(LocalStorage::class)]
#[CoversClass(StorageException::class)]
final class UploadedFileTest extends TestCase
{
    private string $tempFile;

    private string $storageRoot;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'myxa-upload-');
        $this->storageRoot = sys_get_temp_dir() . '/myxa-upload-storage-' . bin2hex(random_bytes(6));

        if (!is_string($this->tempFile)) {
            self::fail('Unable to create temporary upload file.');
        }

        file_put_contents($this->tempFile, 'avatar-bytes');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }

        if (is_dir($this->storageRoot)) {
            $items = scandir($this->storageRoot) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                unlink($this->storageRoot . '/' . $item);
            }

            rmdir($this->storageRoot);
        }
    }

    public function testUploadedFileReadsContentAndStoresViaStorage(): void
    {
        $upload = UploadedFile::fromArray([
            'name' => 'My Avatar.png',
            'type' => 'image/png',
            'size' => 12,
            'tmp_name' => $this->tempFile,
            'error' => 0,
        ], ['png']);

        $storage = new LocalStorage($this->storageRoot);
        $stored = $upload->store($storage);

        self::assertTrue($upload->isValid());
        self::assertSame('My Avatar.png', $upload->name());
        self::assertSame('png', $upload->extension());
        self::assertSame('image/png', $upload->mimeType());
        self::assertSame(base64_encode('avatar-bytes'), $upload->contents(true));
        self::assertSame('My_Avatar.png', $stored->location());
        self::assertSame('My Avatar.png', $stored->name());
        self::assertSame('avatar-bytes', $storage->read('My_Avatar.png'));
    }

    public function testUploadedFileRejectsExtensionMismatch(): void
    {
        $upload = UploadedFile::fromArray([
            'name' => 'avatar.png',
            'type' => 'image/png',
            'size' => 12,
            'tmp_name' => $this->tempFile,
            'error' => 0,
        ], ['jpg']);

        self::assertFalse($upload->isValid());
        self::assertSame(100, $upload->errorCode());
        self::assertSame('File extension "png" not allowed.', $upload->errorMessage());

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('File extension "png" not allowed.');

        $upload->store(new LocalStorage($this->storageRoot));
    }

    public function testUploadedFileMarksCorruptedInput(): void
    {
        $upload = UploadedFile::fromArray(['name' => 'missing-only']);

        self::assertFalse($upload->isValid());
        self::assertSame(900, $upload->errorCode());
        self::assertSame('File input is empty or $_FILES data is corrupted.', $upload->errorMessage());
    }

    public function testUploadedFileExposesMetadataAndCanRename(): void
    {
        $upload = UploadedFile::fromArray([
            'name' => 'My Avatar.png',
            'type' => 'image/png',
            'size' => 12,
            'tmp_name' => $this->tempFile,
            'error' => 0,
        ]);

        $upload->rename(' renamed.jpg ');

        self::assertSame('renamed.jpg', $upload->name());
        self::assertSame('jpg', $upload->extension());
        self::assertSame(12, $upload->size());
        self::assertSame($this->tempFile, $upload->tempPath());
    }

    public function testUploadedFileReportsPhpErrorsAndReadFailures(): void
    {
        $partial = UploadedFile::fromArray([
            'name' => 'partial.txt',
            'type' => 'text/plain',
            'size' => 3,
            'tmp_name' => $this->tempFile,
            'error' => 3,
        ]);

        self::assertSame('The uploaded file was only partially uploaded.', $partial->errorMessage());

        $missingFolder = UploadedFile::fromArray([
            'name' => 'missing.txt',
            'type' => 'text/plain',
            'size' => 3,
            'tmp_name' => $this->tempFile,
            'error' => 6,
        ]);

        self::assertSame('Missing a temporary folder.', $missingFolder->errorMessage());

        $invalidRead = UploadedFile::fromArray([
            'name' => 'ghost.txt',
            'type' => 'text/plain',
            'size' => 3,
            'tmp_name' => $this->tempFile . '.missing',
            'error' => 0,
        ]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage(sprintf(
            'Unable to read uploaded file from "%s".',
            $this->tempFile . '.missing',
        ));

        $invalidRead->contents();
    }
}
