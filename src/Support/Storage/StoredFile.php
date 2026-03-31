<?php

declare(strict_types=1);

namespace Myxa\Support\Storage;

final readonly class StoredFile
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $storage,
        private string $location,
        private string $name,
        private int $size,
        private ?string $mimeType = null,
        private ?string $checksum = null,
        private array $metadata = [],
    ) {
    }

    /**
     * Return the storage alias that owns this file.
     */
    public function storage(): string
    {
        return $this->storage;
    }

    /**
     * Return the normalized location inside storage.
     */
    public function location(): string
    {
        return $this->location;
    }

    /**
     * Return the original display name of the file.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the file extension extracted from the display name.
     */
    public function extension(): string
    {
        $extension = pathinfo($this->name, PATHINFO_EXTENSION);

        return is_string($extension) ? $extension : '';
    }

    /**
     * Return the file size in bytes.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Return the MIME type when one is known.
     */
    public function mimeType(): ?string
    {
        return $this->mimeType;
    }

    /**
     * Return the content checksum when one is known.
     */
    public function checksum(): ?string
    {
        return $this->checksum;
    }

    /**
     * Return all metadata or a single metadata value.
     *
     * @return array<string, mixed>|mixed
     */
    public function metadata(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }

        return $this->metadata[$key] ?? $default;
    }
}
