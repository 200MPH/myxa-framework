<?php

declare(strict_types=1);

namespace Myxa\Storage\Local;

use Myxa\Storage\AbstractStorage;
use Myxa\Storage\Exceptions\StorageException;
use Myxa\Storage\StoredFile;

final class LocalStorage extends AbstractStorage
{
    /**
     * @param string $root Absolute root directory used by this storage.
     * @param string $alias Public storage alias.
     */
    public function __construct(
        private readonly string $root,
        string $alias = 'local',
    ) {
        parent::__construct($alias);
    }

    /**
     * Persist contents to the local filesystem.
     *
     * @param array{name?: string, mime_type?: string, metadata?: array<string, mixed>} $options
     */
    public function put(string $location, string $contents, array $options = []): StoredFile
    {
        $location = $this->normalizeLocation($location);
        $path = $this->path($location);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new StorageException(sprintf('Unable to create directory "%s".', $directory));
        }

        $written = @file_put_contents($path, $contents);
        if ($written === false) {
            throw new StorageException(sprintf('Unable to write file "%s".', $path));
        }

        return $this->buildStoredFile($location, $options, $path);
    }

    /**
     * Return file metadata for a location on disk.
     */
    public function get(string $location): ?StoredFile
    {
        $location = $this->normalizeLocation($location);
        $path = $this->path($location);

        if (!is_file($path)) {
            return null;
        }

        return $this->buildStoredFile($location, [], $path);
    }

    /**
     * Read file contents from disk.
     */
    public function read(string $location): string
    {
        $location = $this->normalizeLocation($location);
        $path = $this->path($location);
        $contents = @file_get_contents($path);

        if (!is_string($contents)) {
            throw new StorageException(sprintf('Unable to read file "%s".', $path));
        }

        return $contents;
    }

    /**
     * Delete a file from disk.
     */
    public function delete(string $location): bool
    {
        $location = $this->normalizeLocation($location);
        $path = $this->path($location);

        return is_file($path) ? unlink($path) : false;
    }

    /**
     * Determine whether a file exists on disk.
     */
    public function exists(string $location): bool
    {
        return is_file($this->path($this->normalizeLocation($location)));
    }

    /**
     * Resolve an absolute filesystem path for a storage location.
     */
    public function path(string $location): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $this->normalizeLocation($location));
    }

    /**
     * @param array{name?: string, mime_type?: string, metadata?: array<string, mixed>} $options
     */
    private function buildStoredFile(string $location, array $options, string $path): StoredFile
    {
        $size = filesize($path);
        if ($size === false) {
            throw new StorageException(sprintf('Unable to determine file size for "%s".', $path));
        }

        $mimeType = $this->resolveMimeType($options, $this->detectMimeType($path));
        $metadata = $this->resolveMetadata($options) + ['absolute_path' => $path];
        $checksum = sha1_file($path);

        return new StoredFile(
            $this->alias(),
            $location,
            $this->resolveName($location, $options),
            $size,
            $mimeType,
            is_string($checksum) ? $checksum : null,
            $metadata,
        );
    }

    private function detectMimeType(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mimeType = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mimeType) ? $mimeType : null;
    }
}
