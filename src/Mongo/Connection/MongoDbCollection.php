<?php

declare(strict_types=1);

namespace Myxa\Mongo\Connection;

use MongoDB\BSON\ObjectId;
use RuntimeException;
use Traversable;

final class MongoDbCollection implements MongoCollectionInterface
{
    public function __construct(private readonly object $collection)
    {
        foreach (['findOne', 'insertOne', 'replaceOne', 'deleteOne'] as $method) {
            if (!method_exists($this->collection, $method)) {
                throw new RuntimeException(sprintf('Mongo collection object must provide %s().', $method));
            }
        }
    }

    public function findOne(array $filter): ?array
    {
        $document = $this->collection->findOne($this->normalizeFilter($filter));

        return $document === null ? null : $this->normalizeDocument($document);
    }

    public function insertOne(array $document): string|int
    {
        if (array_key_exists('_id', $document) && $document['_id'] === null) {
            unset($document['_id']);
        }

        $result = $this->collection->insertOne($this->normalizeWriteDocument($document));
        if (!is_object($result) || !method_exists($result, 'getInsertedId')) {
            throw new RuntimeException('Mongo insertOne() result must provide getInsertedId().');
        }

        return $this->normalizeId($result->getInsertedId());
    }

    public function updateOne(array $filter, array $document): bool
    {
        $result = $this->collection->replaceOne(
            $this->normalizeFilter($filter),
            $this->normalizeWriteDocument($document),
        );

        if (!is_object($result) || !method_exists($result, 'getMatchedCount')) {
            throw new RuntimeException('Mongo replaceOne() result must provide getMatchedCount().');
        }

        return $result->getMatchedCount() > 0;
    }

    public function deleteOne(array $filter): bool
    {
        $result = $this->collection->deleteOne($this->normalizeFilter($filter));
        if (!is_object($result) || !method_exists($result, 'getDeletedCount')) {
            throw new RuntimeException('Mongo deleteOne() result must provide getDeletedCount().');
        }

        return $result->getDeletedCount() > 0;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function normalizeFilter(array $filter): array
    {
        if (isset($filter['_id']) && is_string($filter['_id']) && $this->looksLikeObjectId($filter['_id'])) {
            $filter['_id'] = new ObjectId($filter['_id']);
        }

        return $filter;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function normalizeWriteDocument(array $document): array
    {
        if (isset($document['_id']) && is_string($document['_id']) && $this->looksLikeObjectId($document['_id'])) {
            $document['_id'] = new ObjectId($document['_id']);
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDocument(mixed $document): array
    {
        if (is_object($document) && method_exists($document, 'getArrayCopy')) {
            $document = $document->getArrayCopy();
        } elseif (is_object($document) && method_exists($document, 'bsonSerialize')) {
            $document = $document->bsonSerialize();
        } elseif ($document instanceof Traversable) {
            $document = iterator_to_array($document);
        }

        if (is_object($document)) {
            $document = get_object_vars($document);
        }

        if (!is_array($document)) {
            throw new RuntimeException('Mongo document must normalize to an array.');
        }

        return $this->normalizeDocumentValues($document);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function normalizeDocumentValues(array $document): array
    {
        foreach ($document as $key => $value) {
            if ($key === '_id') {
                $document[$key] = $this->normalizeId($value);

                continue;
            }

            if (is_array($value)) {
                $document[$key] = $this->normalizeDocumentValues($value);

                continue;
            }

            if (is_object($value) && method_exists($value, 'getArrayCopy')) {
                $document[$key] = $this->normalizeDocumentValues($value->getArrayCopy());
            }
        }

        return $document;
    }

    private function normalizeId(mixed $id): string|int
    {
        if (is_string($id) || is_int($id)) {
            return $id;
        }

        if ($id instanceof ObjectId) {
            return (string) $id;
        }

        if (is_object($id) && method_exists($id, '__toString')) {
            return (string) $id;
        }

        throw new RuntimeException('Mongo document _id must be a string, integer, or stringable value.');
    }

    private function looksLikeObjectId(string $value): bool
    {
        return class_exists(ObjectId::class) && preg_match('/^[a-f0-9]{24}$/i', $value) === 1;
    }
}
