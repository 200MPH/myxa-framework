<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering;

/**
 * Normalized schema metadata for a table index.
 */
final readonly class IndexSchema
{
    public const string TYPE_PRIMARY = 'primary';

    public const string TYPE_UNIQUE = 'unique';

    public const string TYPE_INDEX = 'index';

    /**
     * @param list<string> $columns
     */
    public function __construct(
        private string $name,
        private string $type,
        private array $columns,
    ) {
    }

    /**
     * Return the index name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the normalized index type.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }
}
