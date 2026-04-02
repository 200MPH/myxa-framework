<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use InvalidArgumentException;
use LogicException;
use Myxa\Database\Query\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryBuilder::class)]
final class QueryBuilderTest extends TestCase
{
    public function testBuildsInsertSqlWithBindings(): void
    {
        $builder = (new QueryBuilder())
            ->insertInto('users')
            ->values([
                'email' => 'john@example.com',
                'status' => 'active',
                'age' => 30,
            ]);

        self::assertSame(
            'INSERT INTO `users` (`email`, `status`, `age`) VALUES (?, ?, ?)',
            $builder->toSql(),
        );
        self::assertSame(
            "INSERT INTO `users` (`email`, `status`, `age`) VALUES ('john@example.com', 'active', 30)",
            $builder->debugQuery(),
        );
        self::assertSame(
            ['john@example.com', 'active', 30],
            $builder->getBindings(),
        );
    }

    public function testBuildsUpdateSqlWithBindings(): void
    {
        $builder = (new QueryBuilder())
            ->update('users')
            ->setMany([
                'status' => 'inactive',
                'updated_at' => '2026-04-01T12:00:00+00:00',
            ])
            ->where('id', '=', 5);

        self::assertSame(
            'UPDATE `users` SET `status` = ?, `updated_at` = ? WHERE `id` = ?',
            $builder->toSql(),
        );
        self::assertSame(
            "UPDATE `users` SET `status` = 'inactive', `updated_at` = '2026-04-01T12:00:00+00:00' WHERE `id` = 5",
            $builder->debugQuery(),
        );
        self::assertSame(
            ['inactive', '2026-04-01T12:00:00+00:00', 5],
            $builder->getBindings(),
        );
    }

    public function testBuildsDeleteSqlWithBindings(): void
    {
        $builder = (new QueryBuilder())
            ->deleteFrom('users')
            ->where('status', '=', 'inactive');

        self::assertSame(
            'DELETE FROM `users` WHERE `status` = ?',
            $builder->toSql(),
        );
        self::assertSame(
            "DELETE FROM `users` WHERE `status` = 'inactive'",
            $builder->debugQuery(),
        );
        self::assertSame(
            ['inactive'],
            $builder->getBindings(),
        );
    }

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
            'SELECT `id`, `users`.`email`, `users`.* FROM `app_db`.`users`'
            . " WHERE `status` = 'active' AND `created_at` BETWEEN '2026-01-01' AND '2026-12-31' AND `role` IN ('admin', 'editor')"
            . ' GROUP BY `users`.`id` ORDER BY `created_at` DESC LIMIT 10 OFFSET 20',
            $builder->debugQuery(),
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
        self::assertSame('SELECT * FROM `users`', $builder->debugQuery());
        self::assertSame([], $builder->getBindings());
    }

    public function testToRawSqlEscapesStringBindings(): void
    {
        $builder = (new QueryBuilder())
            ->from('users')
            ->where('email', '=', "o'reilly@example.com");

        self::assertSame(
            "SELECT * FROM `users` WHERE `email` = 'o''reilly@example.com'",
            $builder->debugQuery(),
        );
    }

    public function testResetClearsState(): void
    {
        $builder = (new QueryBuilder())
            ->update('users')
            ->set('status', 'pending')
            ->where('id', '>', 1)
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

    public function testInsertToSqlThrowsWhenValuesAreMissing(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('VALUES are required before generating INSERT SQL.');

        (new QueryBuilder())
            ->insertInto('users')
            ->toSql();
    }

    public function testUpdateToSqlThrowsWhenSetValuesAreMissing(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SET values are required before generating UPDATE SQL.');

        (new QueryBuilder())
            ->update('users')
            ->toSql();
    }

    public function testWhereThrowsForUnsupportedOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryBuilder())
            ->from('users')
            ->where('id', 'IS', 1);
    }

    public function testWhereThrowsWhenUsedWithInsertQuery(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('WHERE clauses cannot be used with INSERT queries.');

        (new QueryBuilder())
            ->insertInto('users')
            ->where('id', '=', 1);
    }

    public function testWhereInThrowsForEmptyValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryBuilder())
            ->from('users')
            ->whereIn('id', []);
    }

    public function testValuesThrowsForEmptyInsertPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Values for INSERT cannot be empty.');

        (new QueryBuilder())
            ->insertInto('users')
            ->values([]);
    }

    public function testSetManyThrowsForEmptyUpdatePayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Values for UPDATE cannot be empty.');

        (new QueryBuilder())
            ->update('users')
            ->setMany([]);
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

    public function testCannotSwitchStatementTypeWithoutResetting(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot switch builder from SELECT to UPDATE without calling reset().');

        (new QueryBuilder())
            ->from('users')
            ->update('users');
    }
}
