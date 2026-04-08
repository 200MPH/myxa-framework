<?php

declare(strict_types=1);

namespace Myxa\Mongo\Connection;

final class InMemoryMongoCollection implements MongoCollectionInterface
{
    /** @var array<string|int, array<string, mixed>> */
    private array $documents = [];

    private int $nextNumericId = 1;

    public function findOne(array $filter): ?array
    {
        foreach ($this->documents as $document) {
            if ($this->matches($document, $filter)) {
                return $document;
            }
        }

        return null;
    }

    public function insertOne(array $document): string|int
    {
        $id = $document['_id'] ?? $this->nextNumericId++;
        $document['_id'] = $id;
        $this->documents[$id] = $document;

        return $id;
    }

    public function updateOne(array $filter, array $document): bool
    {
        foreach ($this->documents as $id => $stored) {
            if (!$this->matches($stored, $filter)) {
                continue;
            }

            $document['_id'] ??= $stored['_id'] ?? $id;
            $this->documents[$id] = $document;

            return true;
        }

        return false;
    }

    public function deleteOne(array $filter): bool
    {
        foreach ($this->documents as $id => $document) {
            if (!$this->matches($document, $filter)) {
                continue;
            }

            unset($this->documents[$id]);

            return true;
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->documents);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $filter
     */
    private function matches(array $document, array $filter): bool
    {
        foreach ($filter as $key => $value) {
            if (($document[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
