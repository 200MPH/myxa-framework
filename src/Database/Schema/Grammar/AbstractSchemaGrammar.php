<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\Grammar;

use LogicException;
use Myxa\Database\Query\RawExpression;
use Myxa\Database\Schema\Blueprint;
use Myxa\Database\Schema\ColumnDefinition;
use Myxa\Database\Schema\ForeignKeyDefinition;
use Myxa\Database\Schema\IndexDefinition;

/**
 * Shared SQL generation helpers for schema grammars.
 */
abstract class AbstractSchemaGrammar implements SchemaGrammarInterface
{
    /**
     * @return list<string>
     */
    public function compileCreate(Blueprint $blueprint): array
    {
        $definitions = [];

        foreach ($blueprint->columns() as $column) {
            $definitions[] = $this->compileCreateColumn($column);
        }

        foreach ($blueprint->indexes() as $index) {
            if ($index->type() !== IndexDefinition::TYPE_PRIMARY) {
                continue;
            }

            $definitions[] = $this->compilePrimaryConstraint($index);
        }

        foreach ($blueprint->foreignKeys() as $foreignKey) {
            $definitions[] = $this->compileForeignKeyConstraint($foreignKey);
        }

        if ($definitions === []) {
            throw new LogicException(sprintf(
                'Cannot create table "%s" without any columns or constraints.',
                $blueprint->tableName(),
            ));
        }

        $statements = [
            sprintf(
                'CREATE TABLE %s (%s)',
                $this->wrap($blueprint->tableName()),
                implode(', ', $definitions),
            ),
        ];

        foreach ($blueprint->indexes() as $index) {
            if ($index->type() === IndexDefinition::TYPE_PRIMARY) {
                continue;
            }

            $statements[] = $this->compileIndex($blueprint->tableName(), $index);
        }

        foreach ($blueprint->rawStatements() as $statement) {
            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * @return list<string>
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $statements = [];

        foreach ($blueprint->columns() as $column) {
            $statements[] = $this->compileAddColumn($blueprint->tableName(), $column);
        }

        foreach ($blueprint->renamedColumns() as $rename) {
            $statements[] = $this->compileRenameColumn($blueprint->tableName(), $rename['from'], $rename['to']);
        }

        foreach ($blueprint->droppedColumns() as $column) {
            $statements[] = $this->compileDropColumn($blueprint->tableName(), $column);
        }

        foreach ($blueprint->droppedIndexes() as $name) {
            $statements[] = $this->compileDropIndex($blueprint->tableName(), $name);
        }

        foreach ($blueprint->droppedForeignKeys() as $name) {
            $statements[] = $this->compileDropForeign($blueprint->tableName(), $name);
        }

        foreach ($blueprint->indexes() as $index) {
            $statements[] = $index->type() === IndexDefinition::TYPE_PRIMARY
                ? $this->compileAddPrimaryKey($blueprint->tableName(), $index)
                : $this->compileIndex($blueprint->tableName(), $index);
        }

        foreach ($blueprint->foreignKeys() as $foreignKey) {
            $statements[] = $this->compileAddForeignKey($blueprint->tableName(), $foreignKey);
        }

        foreach ($blueprint->rawStatements() as $statement) {
            $statements[] = $statement;
        }

        return $statements;
    }

    public function compileDrop(string $table, bool $ifExists = false): string
    {
        return sprintf(
            'DROP TABLE%s %s',
            $ifExists ? ' IF EXISTS' : '',
            $this->wrap($table),
        );
    }

    public function compileRename(string $from, string $to): string
    {
        return sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $this->wrap($from),
            $this->wrap($to),
        );
    }

    public function compileDropIndex(string $table, string $name): string
    {
        return sprintf('DROP INDEX %s', $this->wrap($name));
    }

    public function compileDropForeign(string $table, string $name): string
    {
        return sprintf(
            'ALTER TABLE %s DROP CONSTRAINT %s',
            $this->wrap($table),
            $this->wrap($name),
        );
    }

    abstract protected function wrap(string $identifier): string;

    abstract protected function compileType(ColumnDefinition $column): string;

    protected function supportsUnsigned(): bool
    {
        return true;
    }

    protected function compileCreateColumn(ColumnDefinition $column): string
    {
        $segments = [$this->wrap($column->name())];

        if ($this->requiresSpecialAutoIncrementPrimarySyntax($column)) {
            $segments[] = $this->compileSpecialAutoIncrementPrimarySyntax($column);

            return implode(' ', $segments);
        }

        $segments[] = $this->compileType($column);

        if ($this->supportsUnsigned() && $column->isUnsigned()) {
            $segments[] = 'UNSIGNED';
        }

        $segments[] = $column->isNullable() ? 'NULL' : 'NOT NULL';

        if ($column->hasDefault()) {
            $segments[] = 'DEFAULT ' . $this->compileDefaultValue($column->defaultValue());
        }

        if ($column->isAutoIncrement()) {
            $keyword = $this->autoIncrementKeyword();
            if ($keyword !== '') {
                $segments[] = $keyword;
            }
        }

        if ($column->isPrimary()) {
            $segments[] = 'PRIMARY KEY';
        }

        return implode(' ', $segments);
    }

    protected function compileAddColumn(string $table, ColumnDefinition $column): string
    {
        return sprintf(
            'ALTER TABLE %s ADD COLUMN %s',
            $this->wrap($table),
            $this->compileCreateColumn($column),
        );
    }

    protected function compileRenameColumn(string $table, string $from, string $to): string
    {
        return sprintf(
            'ALTER TABLE %s RENAME COLUMN %s TO %s',
            $this->wrap($table),
            $this->wrap($from),
            $this->wrap($to),
        );
    }

    protected function compileDropColumn(string $table, string $column): string
    {
        return sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            $this->wrap($table),
            $this->wrap($column),
        );
    }

    protected function compileAddPrimaryKey(string $table, IndexDefinition $index): string
    {
        return sprintf(
            'ALTER TABLE %s ADD PRIMARY KEY (%s)',
            $this->wrap($table),
            $this->columnList($index->columns()),
        );
    }

    protected function compileIndex(string $table, IndexDefinition $index): string
    {
        $prefix = $index->type() === IndexDefinition::TYPE_UNIQUE ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';

        return sprintf(
            '%s %s ON %s (%s)',
            $prefix,
            $this->wrap($index->name()),
            $this->wrap($table),
            $this->columnList($index->columns()),
        );
    }

    protected function compilePrimaryConstraint(IndexDefinition $index): string
    {
        return sprintf('PRIMARY KEY (%s)', $this->columnList($index->columns()));
    }

    protected function compileAddForeignKey(string $table, ForeignKeyDefinition $foreignKey): string
    {
        return sprintf(
            'ALTER TABLE %s ADD %s',
            $this->wrap($table),
            $this->compileForeignKeyConstraint($foreignKey),
        );
    }

    protected function compileForeignKeyConstraint(ForeignKeyDefinition $foreignKey): string
    {
        $segments = [
            sprintf('CONSTRAINT %s', $this->wrap($foreignKey->name())),
            sprintf('FOREIGN KEY (%s)', $this->columnList($foreignKey->columns())),
            sprintf(
                'REFERENCES %s (%s)',
                $this->wrap($foreignKey->table()),
                $this->columnList($foreignKey->referencedColumns()),
            ),
        ];

        if ($foreignKey->deleteAction() !== null) {
            $segments[] = sprintf('ON DELETE %s', $foreignKey->deleteAction());
        }

        if ($foreignKey->updateAction() !== null) {
            $segments[] = sprintf('ON UPDATE %s', $foreignKey->updateAction());
        }

        return implode(' ', $segments);
    }

    protected function autoIncrementKeyword(): string
    {
        return 'AUTO_INCREMENT';
    }

    protected function requiresSpecialAutoIncrementPrimarySyntax(ColumnDefinition $column): bool
    {
        return false;
    }

    protected function compileSpecialAutoIncrementPrimarySyntax(ColumnDefinition $column): string
    {
        return $this->compileType($column) . ' PRIMARY KEY';
    }

    /**
     * @param list<string> $columns
     */
    protected function columnList(array $columns): string
    {
        return implode(', ', array_map($this->wrap(...), $columns));
    }

    protected function compileDefaultValue(mixed $value): string
    {
        if ($value instanceof RawExpression) {
            return $value->getValue();
        }

        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        throw new LogicException('Unsupported default value supplied to schema grammar.');
    }
}
