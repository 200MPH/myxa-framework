<?php

declare(strict_types=1);

namespace Myxa\Database\Schema;

use InvalidArgumentException;

/**
 * Immutable index definition.
 */
final readonly class IndexDefinition
{
    public const string TYPE_PRIMARY = 'primary';

    public const string TYPE_UNIQUE = 'unique';

    public const string TYPE_INDEX = 'index';

    /**
     * @param list<string> $columns
     */
    public function __construct(
        private string $type,
        private array $columns,
        private string $name,
    ) {
        if (!in_array($this->type, [self::TYPE_PRIMARY, self::TYPE_UNIQUE, self::TYPE_INDEX], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported index type "%s".', $this->type));
        }

        if ($this->columns === []) {
            throw new InvalidArgumentException('Index columns cannot be empty.');
        }

        foreach ($this->columns as $column) {
            if (trim($column) === '') {
                throw new InvalidArgumentException('Index column names cannot be empty.');
            }
        }

        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Index name cannot be empty.');
        }
    }

    public function type(): string
    {
        return $this->type;
    }

    /**
     * Return the indexed columns.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Return the index name.
     */
    public function name(): string
    {
        return $this->name;
    }
}
