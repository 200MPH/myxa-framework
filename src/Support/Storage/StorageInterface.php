<?php

declare(strict_types=1);

namespace Myxa\Support\Storage;

interface StorageInterface
{
    /**
     * Persist contents at the given storage location.
     *
     * @param array{name?: string, mime_type?: string, metadata?: array<string, mixed>} $options
     */
    public function put(string $location, string $contents, array $options = []): StoredFile;

    /**
     * Return file metadata for an existing location.
     */
    public function get(string $location): ?StoredFile;

    /**
     * Read raw contents from a stored file.
     */
    public function read(string $location): string;

    /**
     * Delete a file from storage.
     */
    public function delete(string $location): bool;

    /**
     * Determine whether a location exists in storage.
     */
    public function exists(string $location): bool;
}
