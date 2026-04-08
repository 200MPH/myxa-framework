<?php

declare(strict_types=1);

namespace Myxa\Database\Schema\Grammar;

use Myxa\Database\Schema\Blueprint;

interface SchemaGrammarInterface
{
    /**
     * @return list<string>
     */
    public function compileCreate(Blueprint $blueprint): array;

    /**
     * @return list<string>
     */
    public function compileAlter(Blueprint $blueprint): array;

    public function compileDrop(string $table, bool $ifExists = false): string;

    public function compileRename(string $from, string $to): string;

    public function compileDropIndex(string $table, string $name): string;

    public function compileDropForeign(string $table, string $name): string;
}
