<?php

declare(strict_types=1);

namespace Myxa\Storage\Metadata;

use Myxa\Storage\StoredFile;

interface StorageMetadataRepositoryInterface
{
    /**
     * Persist or update metadata for a stored file.
     */
    public function save(StoredFile $file): void;

    /**
     * Return tracked metadata for a file when available.
     */
    public function find(string $storage, string $location): ?StoredFile;

    /**
     * Remove tracked metadata for a file.
     */
    public function delete(string $storage, string $location): bool;
}
