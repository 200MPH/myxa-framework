<?php

declare(strict_types=1);

namespace Myxa\Storage\Metadata;

use Myxa\Storage\AbstractStorage;
use Myxa\Storage\StoredFile;
use Myxa\Storage\StorageInterface;
use Throwable;

final class MetadataTrackingStorage extends AbstractStorage
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly StorageMetadataRepositoryInterface $repository,
        ?string $alias = null,
    ) {
        parent::__construct($alias ?? $this->resolveAlias($storage));
    }

    /**
     * Persist contents through the wrapped storage and sync metadata into the repository.
     *
     * @param array{name?: string, mime_type?: string, metadata?: array<string, mixed>} $options
     */
    public function put(string $location, string $contents, array $options = []): StoredFile
    {
        $location = $this->normalizeLocation($location);
        $stored = $this->normalizeStoredFile($this->storage->put($location, $contents, $options));

        try {
            $this->repository->save($stored);
        } catch (Throwable $exception) {
            try {
                $this->storage->delete($location);
            } catch (Throwable) {
            }

            throw $exception;
        }

        return $stored;
    }

    public function get(string $location): ?StoredFile
    {
        $location = $this->normalizeLocation($location);
        $tracked = $this->repository->find($this->alias(), $location);

        if ($tracked !== null) {
            if ($this->storage->exists($location)) {
                return $this->normalizeStoredFile($tracked);
            }

            $this->repository->delete($this->alias(), $location);

            return null;
        }

        $resolved = $this->storage->get($location);

        return $resolved === null ? null : $this->normalizeStoredFile($resolved);
    }

    public function read(string $location): string
    {
        return $this->storage->read($this->normalizeLocation($location));
    }

    public function delete(string $location): bool
    {
        $location = $this->normalizeLocation($location);
        $deleted = $this->storage->delete($location);
        $metadataDeleted = $this->repository->delete($this->alias(), $location);

        return $deleted || $metadataDeleted;
    }

    public function exists(string $location): bool
    {
        return $this->storage->exists($this->normalizeLocation($location));
    }

    private function normalizeStoredFile(StoredFile $file): StoredFile
    {
        if ($file->storage() === $this->alias()) {
            return $file;
        }

        return new StoredFile(
            $this->alias(),
            $file->location(),
            $file->name(),
            $file->size(),
            $file->mimeType(),
            $file->checksum(),
            $file->metadata(),
        );
    }

    private function resolveAlias(StorageInterface $storage): string
    {
        return method_exists($storage, 'alias') ? $storage->alias() : 'metadata';
    }
}
