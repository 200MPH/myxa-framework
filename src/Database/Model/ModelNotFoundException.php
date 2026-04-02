<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use RuntimeException;

final class ModelNotFoundException extends RuntimeException
{
    /**
     * @param class-string<Model> $modelClass
     */
    private function __construct(
        string $message,
        private readonly string $modelClass,
        private readonly int|string|null $key = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param class-string<Model> $modelClass
     */
    public static function forModel(string $modelClass): self
    {
        return new self(
            sprintf('No records were found for model %s.', $modelClass),
            $modelClass,
        );
    }

    /**
     * @param class-string<Model> $modelClass
     */
    public static function forKey(string $modelClass, int|string $key): self
    {
        return new self(
            sprintf('No record was found for model %s with key "%s".', $modelClass, (string) $key),
            $modelClass,
            $key,
        );
    }

    /**
     * @return class-string<Model>
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    public function getKey(): int|string|null
    {
        return $this->key;
    }
}
