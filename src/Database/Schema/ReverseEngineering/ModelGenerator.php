<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\ReverseEngineering;

use InvalidArgumentException;
use LogicException;

/**
 * Render framework-native model classes from normalized schema metadata.
 */
final class ModelGenerator
{
    /**
     * Generate a model class source string for the provided table definition.
     */
    public function generate(
        TableSchema $table,
        string $className,
        ?string $namespace = null,
        ?string $connection = null,
    ): string {
        [$namespace, $className] = $this->resolveClassName($className, $namespace);
        $primaryKey = $this->resolvePrimaryKey($table);
        $usesTimestamps = $this->usesTimestampsTrait($table);
        $propertyLines = $this->renderPropertyLines($table, $usesTimestamps);

        $imports = ['use Myxa\\Database\\Model\\Model;'];

        if ($usesTimestamps) {
            $imports[] = 'use Myxa\\Database\\Model\\HasTimestamps;';
        }

        if ($this->requiresDateTypeImport($table, $usesTimestamps)) {
            $imports[] = 'use DateTimeImmutable;';
        }

        if ($this->requiresCastImport($table, $usesTimestamps)) {
            $imports[] = 'use Myxa\\Database\\Attributes\\Cast;';
            $imports[] = 'use Myxa\\Database\\Model\\CastType;';
        }

        $lines = ['<?php', '', 'declare(strict_types=1);', ''];

        if ($namespace !== null) {
            $lines[] = sprintf('namespace %s;', $namespace);
            $lines[] = '';
        }

        sort($imports);
        foreach ($imports as $import) {
            $lines[] = $import;
        }

        $lines[] = '';
        $lines[] = sprintf('final class %s extends Model', $className);
        $lines[] = '{';

        if ($usesTimestamps) {
            $lines[] = '    use HasTimestamps;';
            $lines[] = '';
        }

        $lines[] = sprintf("    protected string \$table = '%s';", $table->name());

        if ($primaryKey !== 'id') {
            $lines[] = sprintf("    protected string \$primaryKey = '%s';", $primaryKey);
        }

        if ($connection !== null) {
            $lines[] = sprintf("    protected ?string \$connection = '%s';", $connection);
        }

        if ($propertyLines !== []) {
            $lines[] = '';
            foreach ($propertyLines as $propertyLine) {
                $lines[] = $propertyLine;
            }
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function resolveClassName(string $className, ?string $namespace): array
    {
        $className = trim($className);
        $namespace = $namespace !== null ? trim($namespace, " \t\n\r\0\x0B\\") : null;

        if ($className === '') {
            throw new InvalidArgumentException('Model class name cannot be empty.');
        }

        if ($namespace === null && str_contains($className, '\\')) {
            $className = trim($className, '\\');
            $namespace = substr($className, 0, (int) strrpos($className, '\\')) ?: null;
            $className = substr($className, ((int) strrpos($className, '\\')) + 1);
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $className)) {
            throw new InvalidArgumentException(sprintf('Invalid model class name "%s".', $className));
        }

        return [$namespace, $className];
    }

    private function resolvePrimaryKey(TableSchema $table): string
    {
        $primaryColumns = [];

        foreach ($table->columns() as $column) {
            if ($column->isPrimary()) {
                $primaryColumns[] = $column->name();
            }
        }

        foreach ($table->indexes() as $index) {
            if ($index->type() !== IndexSchema::TYPE_PRIMARY) {
                continue;
            }

            $primaryColumns = array_values(array_unique([...$primaryColumns, ...$index->columns()]));
        }

        if ($primaryColumns === []) {
            return 'id';
        }

        if (count($primaryColumns) > 1) {
            throw new LogicException(sprintf(
                'Model generation does not support composite primary keys for table "%s".',
                $table->name(),
            ));
        }

        return $primaryColumns[0];
    }

    private function usesTimestampsTrait(TableSchema $table): bool
    {
        $createdAt = null;
        $updatedAt = null;

        foreach ($table->columns() as $column) {
            if ($column->name() === 'created_at') {
                $createdAt = $column;
            }

            if ($column->name() === 'updated_at') {
                $updatedAt = $column;
            }
        }

        return $createdAt instanceof ColumnSchema
            && $updatedAt instanceof ColumnSchema
            && $this->isDateColumn($createdAt)
            && $this->isDateColumn($updatedAt);
    }

    private function requiresDateTypeImport(TableSchema $table, bool $usesTimestamps): bool
    {
        foreach ($table->columns() as $column) {
            if ($usesTimestamps && ($column->name() === 'created_at' || $column->name() === 'updated_at')) {
                continue;
            }

            if ($this->isDateColumn($column)) {
                return true;
            }
        }

        return false;
    }

    private function requiresCastImport(TableSchema $table, bool $usesTimestamps): bool
    {
        foreach ($table->columns() as $column) {
            if ($usesTimestamps && ($column->name() === 'created_at' || $column->name() === 'updated_at')) {
                continue;
            }

            if ($this->isDateColumn($column) || $column->type() === 'json') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function renderPropertyLines(TableSchema $table, bool $usesTimestamps): array
    {
        $lines = [];

        foreach ($table->columns() as $column) {
            if ($usesTimestamps && ($column->name() === 'created_at' || $column->name() === 'updated_at')) {
                continue;
            }

            $lines = [...$lines, ...$this->renderProperty($column)];
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderProperty(ColumnSchema $column): array
    {
        $name = $column->name();
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new LogicException(sprintf(
                'Cannot generate a model property for column "%s"; it is not a valid PHP property name.',
                $name,
            ));
        }

        [$type, $nullable, $default, $attribute] = $this->propertyDefinition($column);
        $lines = [];

        if ($attribute !== null) {
            $lines[] = '    ' . $attribute;
        }

        $line = sprintf(
            '    protected %s%s $%s',
            $nullable ? '?' : '',
            $type,
            $name,
        );

        if ($default !== null) {
            $line .= ' = ' . $default;
        }

        $lines[] = $line . ';';

        return $lines;
    }

    /**
     * @return array{0: string, 1: bool, 2: ?string, 3: ?string}
     */
    private function propertyDefinition(ColumnSchema $column): array
    {
        if ($this->isDateColumn($column)) {
            return [
                'DateTimeImmutable',
                true,
                'null',
                '#[Cast(CastType::DateTimeImmutable)]',
            ];
        }

        if ($column->type() === 'json') {
            return [
                'array',
                $column->isNullable(),
                $column->hasDefault()
                    ? $this->exportDefaultValue($column->defaultValue(), 'array')
                    : ($column->isNullable() ? 'null' : null),
                '#[Cast(CastType::Json)]',
            ];
        }

        $type = match ($column->type()) {
            'integer', 'bigInteger' => 'int',
            'decimal', 'float' => 'float',
            'boolean' => 'bool',
            default => 'string',
        };

        $nullable = $column->isNullable() || $column->isAutoIncrement();
        $default = null;

        if ($column->isAutoIncrement()) {
            $default = 'null';
        } elseif ($column->hasDefault()) {
            $default = $this->exportDefaultValue($column->defaultValue(), $type);
            if ($default === 'null') {
                $nullable = true;
            }
        } elseif ($nullable) {
            $default = 'null';
        }

        return [$type, $nullable, $default, null];
    }

    private function exportDefaultValue(mixed $value, string $type): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($type === 'array') {
            return is_array($value) ? var_export($value, true) : 'null';
        }

        return var_export($value, true);
    }

    private function isDateColumn(ColumnSchema $column): bool
    {
        return $column->type() === 'timestamp' || $column->type() === 'dateTime';
    }
}
