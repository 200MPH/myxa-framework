<?php

declare(strict_types=1);

namespace Myxa\Database\Schema;

use InvalidArgumentException;
use LogicException;

/**
 * Fluent foreign key definition.
 */
final class ForeignKeyDefinition
{
    private ?string $onTable = null;

    /** @var list<string> */
    private array $references = [];

    private ?string $onDelete = null;

    private ?string $onUpdate = null;

    /**
     * @param list<string> $columns
     */
    public function __construct(
        private readonly array $columns,
        private readonly string $name,
    ) {
        if ($this->columns === []) {
            throw new InvalidArgumentException('Foreign key columns cannot be empty.');
        }

        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Foreign key name cannot be empty.');
        }
    }

    /**
     * Define the referenced columns.
     */
    public function references(string|array $columns): self
    {
        $this->references = $this->normalizeColumns($columns);

        return $this;
    }

    /**
     * Define the referenced table.
     */
    public function on(string $table): self
    {
        if (trim($table) === '') {
            throw new InvalidArgumentException('Foreign key table cannot be empty.');
        }

        $this->onTable = $table;

        return $this;
    }

    /**
     * Set the ON DELETE action.
     */
    public function onDelete(string $action): self
    {
        $this->onDelete = $this->normalizeAction($action);

        return $this;
    }

    /**
     * Set the ON UPDATE action.
     */
    public function onUpdate(string $action): self
    {
        $this->onUpdate = $this->normalizeAction($action);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Return the foreign key constraint name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the referenced table name.
     */
    public function table(): string
    {
        return $this->onTable ?? throw new LogicException(sprintf(
            'Foreign key "%s" must declare a target table with on().',
            $this->name,
        ));
    }

    /**
     * @return list<string>
     */
    public function referencedColumns(): array
    {
        if ($this->references === []) {
            throw new LogicException(sprintf(
                'Foreign key "%s" must declare referenced columns with references().',
                $this->name,
            ));
        }

        return $this->references;
    }

    /**
     * Return the configured ON DELETE action, if any.
     */
    public function deleteAction(): ?string
    {
        return $this->onDelete;
    }

    /**
     * Return the configured ON UPDATE action, if any.
     */
    public function updateAction(): ?string
    {
        return $this->onUpdate;
    }

    /**
     * @return list<string>
     */
    private function normalizeColumns(string|array $columns): array
    {
        $normalized = is_array($columns) ? array_values($columns) : [$columns];

        if ($normalized === []) {
            throw new InvalidArgumentException('Foreign key referenced columns cannot be empty.');
        }

        foreach ($normalized as $column) {
            if (!is_string($column) || trim($column) === '') {
                throw new InvalidArgumentException('Foreign key column names must be non-empty strings.');
            }
        }

        return $normalized;
    }

    private function normalizeAction(string $action): string
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $action) ?? ''));

        if ($normalized === '') {
            throw new InvalidArgumentException('Foreign key action cannot be empty.');
        }

        return $normalized;
    }
}
