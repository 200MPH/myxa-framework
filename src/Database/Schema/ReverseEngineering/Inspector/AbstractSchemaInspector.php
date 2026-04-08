<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering\Inspector;

use InvalidArgumentException;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Schema\ReverseEngineering\ColumnSchema;

/**
 * Shared helpers for driver-specific schema inspectors.
 */
abstract class AbstractSchemaInspector implements SchemaInspectorInterface
{
    public function __construct(
        protected readonly DatabaseManager $manager,
        protected readonly ?string $connection = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function select(string $sql, array $bindings = []): array
    {
        return $this->manager->select($sql, $bindings, $this->connection);
    }

    protected function normalizeColumnType(
        string $databaseType,
        bool $autoIncrement = false,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
    ): array {
        $type = strtolower(trim($databaseType));

        return match (true) {
            $autoIncrement && (
                str_contains($type, 'bigint')
                || str_contains($type, 'int')
                || $type === 'bigserial'
                || $type === 'serial'
            ) => [
                'type' => 'bigInteger',
                'options' => [],
            ],
            str_contains($type, 'bigint'), $type === 'bigserial' => [
                'type' => 'bigInteger',
                'options' => [],
            ],
            str_contains($type, 'int'), $type === 'serial' => [
                'type' => 'integer',
                'options' => [],
            ],
            str_contains($type, 'varying'), str_contains($type, 'varchar'), str_contains($type, 'character') => [
                'type' => 'string',
                'options' => ['length' => $length ?? 255],
            ],
            $type === 'text', str_contains($type, 'clob') => [
                'type' => 'text',
                'options' => [],
            ],
            $type === 'json', $type === 'jsonb' => [
                'type' => 'json',
                'options' => [],
            ],
            str_contains($type, 'bool') => [
                'type' => 'boolean',
                'options' => [],
            ],
            str_contains($type, 'timestamp') => [
                'type' => 'timestamp',
                'options' => [],
            ],
            $type === 'datetime' => [
                'type' => 'dateTime',
                'options' => [],
            ],
            str_contains($type, 'decimal'), str_contains($type, 'numeric') => [
                'type' => 'decimal',
                'options' => [
                    'precision' => $precision ?? 8,
                    'scale' => $scale ?? 2,
                ],
            ],
            str_contains($type, 'double'), str_contains($type, 'float'), str_contains($type, 'real') => [
                'type' => 'float',
                'options' => [],
            ],
            default => [
                'type' => 'text',
                'options' => [],
            ],
        };
    }

    protected function normalizeDefaultValue(mixed $value): array
    {
        if ($value === null) {
            return ['value' => null, 'hasDefault' => false];
        }

        if (is_string($value)) {
            $normalized = trim($value);
            $unquoted = trim($normalized, "'");

            if (strcasecmp($unquoted, 'null') === 0) {
                return ['value' => null, 'hasDefault' => true];
            }

            if (
                strcasecmp($unquoted, 'current_timestamp') === 0
                || str_starts_with(strtolower($unquoted), 'current_timestamp(')
            ) {
                return ['value' => 'CURRENT_TIMESTAMP', 'hasDefault' => true];
            }

            if (is_numeric($unquoted)) {
                return [
                    'value' => str_contains($unquoted, '.') ? (float) $unquoted : (int) $unquoted,
                    'hasDefault' => true,
                ];
            }

            return ['value' => $unquoted, 'hasDefault' => true];
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return ['value' => $value, 'hasDefault' => true];
        }

        throw new InvalidArgumentException('Unsupported default value returned from schema inspector.');
    }

    protected function makeColumn(
        string $name,
        string $databaseType,
        bool $nullable,
        mixed $defaultValue,
        bool $unsigned = false,
        bool $autoIncrement = false,
        bool $primary = false,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
    ): ColumnSchema {
        $normalizedType = $this->normalizeColumnType($databaseType, $autoIncrement, $length, $precision, $scale);
        $normalizedDefault = $this->normalizeDefaultValue($defaultValue);

        return new ColumnSchema(
            name: $name,
            type: $normalizedType['type'],
            nullable: $nullable,
            defaultValue: $normalizedDefault['value'],
            hasDefault: $normalizedDefault['hasDefault'],
            unsigned: $unsigned,
            autoIncrement: $autoIncrement,
            primary: $primary,
            options: $normalizedType['options'],
        );
    }
}
