<?php

declare(strict_types=1);

namespace Myxa\Database;

use DateTimeImmutable;
use LogicException;
use Myxa\Database\Attributes\Internal;

trait HasTimestamps
{
    protected ?string $created_at = null;

    protected ?string $updated_at = null;

    #[Internal]
    protected ?string $createdAtColumn = 'created_at';

    #[Internal]
    protected ?string $updatedAtColumn = 'updated_at';

    protected function applyTimestamps(): void
    {
        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);
        $createdAtColumn = $this->normalizeTimestampColumn($this->createdAtColumn, 'createdAtColumn');
        $updatedAtColumn = $this->normalizeTimestampColumn($this->updatedAtColumn, 'updatedAtColumn');

        if (!$this->exists() && $createdAtColumn !== null && $this->getAttribute($createdAtColumn) === null) {
            $this->setAttribute($createdAtColumn, $timestamp);
        }

        if ($updatedAtColumn !== null) {
            $this->setAttribute($updatedAtColumn, $timestamp);
        }
    }

    private function normalizeTimestampColumn(?string $column, string $property): ?string
    {
        if ($column === null) {
            return null;
        }

        $column = trim($column);
        if ($column === '') {
            throw new LogicException(sprintf('Timestamp metadata property "%s" cannot be empty.', $property));
        }

        return $column;
    }
}
