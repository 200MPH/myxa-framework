<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use LogicException;
use Myxa\Database\Attributes\Internal;

trait HasBlameable
{
    /** @var null|callable(static): int|string|null */
    private static $blameResolver = null;

    protected int|string|null $created_by = null;

    protected int|string|null $updated_by = null;

    #[Internal]
    protected ?string $createdByColumn = 'created_by';

    #[Internal]
    protected ?string $updatedByColumn = 'updated_by';

    public static function setBlameResolver(?callable $resolver): void
    {
        self::$blameResolver = $resolver;
    }

    public static function clearBlameResolver(): void
    {
        self::$blameResolver = null;
    }

    protected function applyBlameable(): void
    {
        $actor = $this->resolveBlameableActor();
        if ($actor === null) {
            return;
        }

        $createdByColumn = $this->normalizeBlameableColumn($this->createdByColumn, 'createdByColumn');
        $updatedByColumn = $this->normalizeBlameableColumn($this->updatedByColumn, 'updatedByColumn');

        if (!$this->exists() && $createdByColumn !== null && $this->getAttribute($createdByColumn) === null) {
            $this->setAttribute($createdByColumn, $actor);
        }

        if ($updatedByColumn !== null) {
            $this->setAttribute($updatedByColumn, $actor);
        }
    }
    private function normalizeBlameableColumn(?string $column, string $property): ?string
    {
        if ($column === null) {
            return null;
        }

        $column = trim($column);
        if ($column === '') {
            throw new LogicException(sprintf('Blameable metadata property "%s" cannot be empty.', $property));
        }

        return $column;
    }

    private function resolveBlameableActor(): int|string|null
    {
        $resolver = self::$blameResolver;
        if ($resolver === null) {
            return null;
        }

        $actor = $resolver($this);
        if ($actor === null || is_int($actor) || is_string($actor)) {
            return $actor;
        }

        throw new LogicException('Blame resolver must return an int, string, or null.');
    }
}
