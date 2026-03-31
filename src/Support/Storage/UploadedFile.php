<?php

declare(strict_types=1);

namespace Myxa\Support\Storage;

final class UploadedFile
{
    private const int ERROR_EXTENSION_NOT_ALLOWED = 100;
    private const int ERROR_INVALID_INPUT = 900;

    /** @var list<string> */
    private array $allowedExtensions;

    private string $name;

    private string $type;

    private int $size;

    private string $tmpName;

    private int $errorCode;

    /**
     * Create an uploaded file wrapper from a PHP `$_FILES`-style array.
     *
     * @param array{name?: mixed, type?: mixed, size?: mixed, tmp_name?: mixed, error?: mixed} $fileData
     * @param list<string> $allowedExtensions
     */
    public function __construct(array $fileData, array $allowedExtensions = [])
    {
        $this->allowedExtensions = array_values(array_map(
            static fn (mixed $extension): string => strtolower(trim((string) $extension)),
            $allowedExtensions,
        ));

        if (!$this->isValidFileArray($fileData)) {
            $this->name = '';
            $this->type = '';
            $this->size = 0;
            $this->tmpName = '';
            $this->errorCode = self::ERROR_INVALID_INPUT;

            return;
        }

        $this->name = (string) $fileData['name'];
        $this->type = (string) $fileData['type'];
        $this->size = (int) $fileData['size'];
        $this->tmpName = (string) $fileData['tmp_name'];
        $this->errorCode = (int) $fileData['error'];

        if ($this->errorCode === 0 && $this->allowedExtensions !== []) {
            $extension = strtolower($this->extension());

            if (!in_array($extension, $this->allowedExtensions, true)) {
                $this->errorCode = self::ERROR_EXTENSION_NOT_ALLOWED;
            }
        }
    }

    /**
     * Build an uploaded file wrapper from a PHP `$_FILES`-style array.
     *
     * @param array{name?: mixed, type?: mixed, size?: mixed, tmp_name?: mixed, error?: mixed} $fileData
     * @param list<string> $allowedExtensions
     */
    public static function fromArray(array $fileData, array $allowedExtensions = []): self
    {
        return new self($fileData, $allowedExtensions);
    }

    /**
     * Convert a user-provided name into a safe storage-friendly file name.
     */
    public static function sanitizeFileName(string $name): string
    {
        $name = basename(trim($name));
        $name = preg_replace('/[^a-zA-Z0-9.\-_+]/', '_', $name) ?? 'file';

        return $name === '' ? 'file' : $name;
    }

    /**
     * Replace the original uploaded display name.
     */
    public function rename(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    /**
     * Return the current uploaded file name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the uploaded file extension.
     */
    public function extension(): string
    {
        $extension = pathinfo($this->name, PATHINFO_EXTENSION);

        return is_string($extension) ? $extension : '';
    }

    /**
     * Return the client-provided MIME type.
     */
    public function mimeType(): string
    {
        return $this->type;
    }

    /**
     * Return the uploaded size in bytes.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Return the temporary upload path on disk.
     */
    public function tempPath(): string
    {
        return $this->tmpName;
    }

    /**
     * Return the upload error code.
     */
    public function errorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * Return a readable description of the current upload status.
     */
    public function errorMessage(): string
    {
        return match ($this->errorCode) {
            0 => 'OK!',
            1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
            3 => 'The uploaded file was only partially uploaded.',
            4 => 'No file was uploaded.',
            6 => 'Missing a temporary folder.',
            7 => 'Failed to write file to disk.',
            8 => 'A PHP extension stopped the file upload.',
            self::ERROR_EXTENSION_NOT_ALLOWED => sprintf('File extension "%s" not allowed.', strtolower($this->extension())),
            self::ERROR_INVALID_INPUT => 'File input is empty or $_FILES data is corrupted.',
            default => 'Unrecognized error',
        };
    }

    /**
     * Determine whether the upload passed validation.
     */
    public function isValid(): bool
    {
        return $this->errorCode === 0;
    }

    /**
     * Read the uploaded contents, optionally as base64.
     */
    public function contents(bool $base64Encode = false): string
    {
        if (!$this->isValid()) {
            throw new StorageException($this->errorMessage());
        }

        $contents = @file_get_contents($this->tmpName);
        if (!is_string($contents)) {
            throw new StorageException(sprintf('Unable to read uploaded file from "%s".', $this->tmpName));
        }

        return $base64Encode ? base64_encode($contents) : $contents;
    }

    /**
     * Persist the upload into a storage driver.
     *
     * @param array{name?: string, mime_type?: string, metadata?: array<string, mixed>} $options
     */
    public function store(StorageInterface $storage, ?string $location = null, array $options = []): StoredFile
    {
        if (!$this->isValid()) {
            throw new StorageException($this->errorMessage());
        }

        $resolvedLocation = $location ?? self::sanitizeFileName($this->name);
        $resolvedOptions = [
            'name' => $options['name'] ?? $this->name,
            'mime_type' => $options['mime_type'] ?? $this->type,
            'metadata' => $options['metadata'] ?? [],
        ];

        return $storage->put($resolvedLocation, $this->contents(), $resolvedOptions);
    }

    /**
     * @param array{name?: mixed, type?: mixed, size?: mixed, tmp_name?: mixed, error?: mixed} $fileData
     */
    private function isValidFileArray(array $fileData): bool
    {
        $requiredKeys = ['error', 'name', 'size', 'tmp_name', 'type'];

        sort($requiredKeys);
        $keys = array_keys($fileData);
        sort($keys);

        return $keys === $requiredKeys;
    }
}
