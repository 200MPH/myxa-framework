<?php

declare(strict_types=1);

namespace Test\Unit\Support\Storage;

use Myxa\Support\Facades\Storage as StorageFacade;
use Myxa\Storage\Local\LocalStorage;
use Myxa\Storage\Exceptions\StorageException;
use Myxa\Storage\StorageManager;
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

    /** @var list<string> */
    private array $storageRoots = [];

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'myxa-upload-');
        $this->storageRoot = sys_get_temp_dir() . '/myxa-upload-storage-' . bin2hex(random_bytes(6));

        if (!is_string($this->tempFile)) {
            self::fail('Unable to create temporary upload file.');
        }

        file_put_contents($this->tempFile, 'avatar-bytes');
        $this->storageRoots = [$this->storageRoot];
    }

    protected function tearDown(): void
    {
        StorageFacade::clearManager();

        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }

        foreach ($this->storageRoots as $storageRoot) {
            $this->deleteDirectory($storageRoot);
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

    public function testUploadedFileStoresThroughDefaultFacadeStorageWhenNoStorageIsPassed(): void
    {
        $manager = new StorageManager('local');
        $storage = new LocalStorage($this->storageRoot);
        $manager->addStorage('local', $storage);
        StorageFacade::setManager($manager);

        $upload = UploadedFile::fromArray([
            'name' => 'avatar.png',
            'type' => 'image/png',
            'size' => 12,
            'tmp_name' => $this->tempFile,
            'error' => 0,
        ]);

        $stored = $upload->store();

        self::assertSame('local', $stored->storage());
        self::assertSame('avatar.png', $stored->location());
        self::assertSame('avatar-bytes', $storage->read('avatar.png'));
    }

    public function testUploadedFileStoresThroughNamedFacadeStorageAndMetadata(): void
    {
        $localRoot = $this->storageRoot . '-local';
        $remoteRoot = $this->storageRoot . '-remote';
        $this->storageRoots[] = $localRoot;
        $this->storageRoots[] = $remoteRoot;

        $manager = new StorageManager('local');
        $manager->addStorage('local', new LocalStorage($localRoot));
        $manager->addStorage('remote', new LocalStorage($remoteRoot, 'remote'));
        StorageFacade::setManager($manager);

        $upload = UploadedFile::fromArray([
            'name' => 'avatar.png',
            'type' => 'image/png',
            'size' => 12,
            'tmp_name' => $this->tempFile,
            'error' => 0,
        ]);

        $stored = $upload->store(
            'profiles/me.png',
            ['metadata' => ['owner' => 'user-1']],
            'remote',
        );

        self::assertSame('remote', $stored->storage());
        self::assertSame('profiles/me.png', $stored->location());
        self::assertSame('user-1', $stored->metadata('owner'));
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

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];
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
