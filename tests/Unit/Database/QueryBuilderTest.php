<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use InvalidArgumentException;
use LogicException;
use Myxa\Database\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryBuilder::class)]
final class QueryBuilderTest extends TestCase
{
    public function testBuildsSqlWithBindingsForComplexQuery(): void
    {
        $builder = (new QueryBuilder())
            ->select('id', 'users.email', 'users.*')
            ->from('users', 'app_db')
            ->where('status', '=', 'active')
            ->whereBetween('created_at', '2026-01-01', '2026-12-31')
            ->whereIn('role', ['admin', 'editor'])
            ->groupBy('users.id')
            ->orderBy('created_at', 'desc')
            ->limit(10, 20);

        self::assertSame(
            'SELECT `id`, `users`.`email`, `users`.* FROM `app_db`.`users`'
            . ' WHERE `status` = ? AND `created_at` BETWEEN ? AND ? AND `role` IN (?, ?)'
            . ' GROUP BY `users`.`id` ORDER BY `created_at` DESC LIMIT 10 OFFSET 20',
            $builder->toSql(),
        );
        self::assertSame(
            ['active', '2026-01-01', '2026-12-31', 'admin', 'editor'],
            $builder->getBindings(),
        );
    }

    public function testBuildsMinimalQueryWithDefaultSelect(): void
    {
        $builder = (new QueryBuilder())->from('users');

        self::assertSame('SELECT * FROM `users`', $builder->toSql());
        self::assertSame([], $builder->getBindings());
    }

    public function testResetClearsState(): void
    {
        $builder = (new QueryBuilder())
            ->select('id')
            ->from('users')
            ->where('id', '>', 1)
            ->limit(5)
            ->reset()
            ->from('logs');

        self::assertSame('SELECT * FROM `logs`', $builder->toSql());
        self::assertSame([], $builder->getBindings());
    }

    public function testToSqlThrowsWhenFromIsMissing(): void
    {
        $this->expectException(LogicException::class);

        (new QueryBuilder())->toSql();
    }

    public function testWhereThrowsForUnsupportedOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryBuilder())
            ->from('users')
            ->where('id', 'IS', 1);
    }

    public function testWhereInThrowsForEmptyValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryBuilder())
            ->from('users')
            ->whereIn('id', []);
    }

    public function testOrderByThrowsForInvalidDirection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryBuilder())
            ->from('users')
            ->orderBy('id', 'SIDEWAYS');
    }

    public function testLimitThrowsForZeroLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryBuilder())
            ->from('users')
            ->limit(0);
    }

    public function testLimitThrowsForNegativeOffset(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryBuilder())
            ->from('users')
            ->limit(1, -1);
    }
}
