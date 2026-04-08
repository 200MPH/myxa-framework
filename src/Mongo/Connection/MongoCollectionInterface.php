<?php

declare(strict_types=1);

namespace Myxa\Mongo\Connection;

interface MongoCollectionInterface
{
    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>|null
     */
    public function findOne(array $filter): ?array;

    /**
     * @param array<string, mixed> $document
     */
    public function insertOne(array $document): string|int;

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $document
     */
    public function updateOne(array $filter, array $document): bool;

    /**
     * @param array<string, mixed> $filter
     */
    public function deleteOne(array $filter): bool;
}
