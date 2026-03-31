<?php

declare(strict_types=1);

namespace Test\Unit\Support\Storage;

use InvalidArgumentException;
use Myxa\Support\Storage\Local\LocalStorage;
use Myxa\Support\Storage\StoredFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalStorage::class)]
#[CoversClass(StoredFile::class)]
final class LocalStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/myxa-local-storage-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->root);
    }

    public function testLocalStoragePersistsReadsAndDeletesFiles(): void
    {
        $storage = new LocalStorage($this->root);

        $stored = $storage->put(
            'avatars/user-1.txt',
            'hello world',
            ['mime_type' => 'text/plain', 'metadata' => ['owner' => 'user-1']],
        );

        self::assertSame('local', $stored->storage());
        self::assertSame('avatars/user-1.txt', $stored->location());
        self::assertSame('user-1.txt', $stored->name());
        self::assertSame('txt', $stored->extension());
        self::assertSame(11, $stored->size());
        self::assertSame('text/plain', $stored->mimeType());
        self::assertSame(sha1('hello world'), $stored->checksum());
        self::assertSame('user-1', $stored->metadata('owner'));
        self::assertTrue(is_string($stored->metadata('absolute_path')));
        self::assertTrue($storage->exists('avatars/user-1.txt'));
        self::assertSame('hello world', $storage->read('avatars/user-1.txt'));

        $resolved = $storage->get('avatars/user-1.txt');

        self::assertInstanceOf(StoredFile::class, $resolved);
        self::assertSame('avatars/user-1.txt', $resolved->location());
        self::assertSame('user-1.txt', $resolved->name());
        self::assertSame('hello world', file_get_contents($resolved->metadata('absolute_path')));
        self::assertTrue($storage->delete('avatars/user-1.txt'));
        self::assertFalse($storage->exists('avatars/user-1.txt'));
        self::assertNull($storage->get('avatars/user-1.txt'));
    }

    public function testLocalStorageRejectsTraversalSegments(): void
    {
        $storage = new LocalStorage($this->root);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File location cannot contain traversal segments.');

        $storage->put('../escape.txt', 'nope');
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
