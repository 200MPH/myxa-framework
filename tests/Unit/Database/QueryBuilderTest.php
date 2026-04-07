<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use InvalidArgumentException;
use LogicException;
use Myxa\Database\Query\Grammar\AbstractQueryGrammar;
use Myxa\Database\Query\Grammar\MysqlQueryGrammar;
use Myxa\Database\Query\Grammar\PostgresQueryGrammar;
use Myxa\Database\Query\Grammar\SqliteQueryGrammar;
use Myxa\Database\Query\Grammar\SqlServerQueryGrammar;
use Myxa\Database\Query\JoinClause;
use Myxa\Database\Query\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryBuilder::class)]
#[CoversClass(JoinClause::class)]
#[CoversClass(AbstractQueryGrammar::class)]
#[CoversClass(MysqlQueryGrammar::class)]
#[CoversClass(PostgresQueryGrammar::class)]
#[CoversClass(SqliteQueryGrammar::class)]
#[CoversClass(SqlServerQueryGrammar::class)]
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
            ->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
            ->where('status', '=', 'active')
            ->whereBetween('created_at', '2026-01-01', '2026-12-31')
            ->whereIn('role', ['admin', 'editor'])
            ->groupBy('users.id')
            ->orderBy('created_at', 'desc')
            ->limit(10, 20);

        self::assertSame(
            'SELECT `id`, `users`.`email`, `users`.* FROM `app_db`.`users`'
            . ' LEFT JOIN `profiles` ON `profiles`.`user_id` = `users`.`id`'
            . ' WHERE `status` = ? AND `created_at` BETWEEN ? AND ? AND `role` IN (?, ?)'
            . ' GROUP BY `users`.`id` ORDER BY `created_at` DESC LIMIT 10 OFFSET 20',
            $builder->toSql(),
        );
        self::assertSame(
            'SELECT `id`, `users`.`email`, `users`.* FROM `app_db`.`users`'
            . ' LEFT JOIN `profiles` ON `profiles`.`user_id` = `users`.`id`'
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

    public function testJoinThrowsWhenUsedWithUpdateQuery(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('JOIN can only be used with SELECT queries.');

        (new QueryBuilder())
            ->update('users')
            ->join('profiles', 'profiles.user_id', '=', 'users.id');
    }

    public function testJoinSupportsAliasesAndMultipleOnConditionsViaClosure(): void
    {
        $builder = (new QueryBuilder())
            ->select('u.id', 'p.user_id')
            ->from('users as u')
            ->join('profiles as p', static function (JoinClause $join): void {
                $join->on('u.id', '=', 'p.user_id')
                    ->on('p.status', '=', 'u.status');
            })
            ->where('u.status', '=', 'active');

        self::assertSame(
            'SELECT `u`.`id`, `p`.`user_id` FROM `users` AS `u`'
            . ' INNER JOIN `profiles` AS `p` ON `u`.`id` = `p`.`user_id` AND `p`.`status` = `u`.`status`'
            . ' WHERE `u`.`status` = ?',
            $builder->toSql(),
        );
        self::assertSame(
            "SELECT `u`.`id`, `p`.`user_id` FROM `users` AS `u`"
            . " INNER JOIN `profiles` AS `p` ON `u`.`id` = `p`.`user_id` AND `p`.`status` = `u`.`status`"
            . " WHERE `u`.`status` = 'active'",
            $builder->debugQuery(),
        );
    }

    public function testJoinClauseSupportsBoundValuesInsideOnConditions(): void
    {
        $builder = (new QueryBuilder())
            ->select('u.id')
            ->from('users as u')
            ->join('profiles as p', static function (JoinClause $join): void {
                $join->on('u.id', '=', 'p.user_id')
                    ->where('p.status', '=', 5);
            });

        self::assertSame(
            'SELECT `u`.`id` FROM `users` AS `u`'
            . ' INNER JOIN `profiles` AS `p` ON `u`.`id` = `p`.`user_id` AND `p`.`status` = ?',
            $builder->toSql(),
        );
        self::assertSame([5], $builder->getBindings());
        self::assertSame(
            'SELECT `u`.`id` FROM `users` AS `u`'
            . ' INNER JOIN `profiles` AS `p` ON `u`.`id` = `p`.`user_id` AND `p`.`status` = 5',
            $builder->debugQuery(),
        );
    }

    public function testPostgresGrammarBuildsDialectAwareSql(): void
    {
        $builder = (new QueryBuilder(new PostgresQueryGrammar()))
            ->select('users.id', 'users.*')
            ->from('users as u', 'app_db')
            ->join('profiles as p', 'p.user_id', '=', 'u.id')
            ->where('u.status', '=', 'active')
            ->limit(5, 10);

        self::assertSame(
            'SELECT "users"."id", "users".* FROM "app_db"."users" AS "u"'
            . ' INNER JOIN "profiles" AS "p" ON "p"."user_id" = "u"."id"'
            . ' WHERE "u"."status" = ? LIMIT 5 OFFSET 10',
            $builder->toSql(),
        );
    }

    public function testSqliteGrammarBuildsDialectAwareSql(): void
    {
        $builder = (new QueryBuilder(new SqliteQueryGrammar()))
            ->update('users')
            ->set('status', 'archived')
            ->where('users.id', '=', 5);

        self::assertSame(
            'UPDATE "users" SET "status" = ? WHERE "users"."id" = ?',
            $builder->toSql(),
        );
    }

    public function testDialectGrammarValidationMessagesStayHelpful(): void
    {
        try {
            (new QueryBuilder(new PostgresQueryGrammar()))->from('bad"name')->toSql();
            self::fail('Expected invalid PostgreSQL identifier exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Identifier cannot contain double quotes.', $exception->getMessage());
        }

        try {
            (new QueryBuilder(new MysqlQueryGrammar()))->from('bad`name')->toSql();
            self::fail('Expected invalid MySQL identifier exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Identifier cannot contain backticks.', $exception->getMessage());
        }
    }

    public function testSqlServerGrammarBuildsDialectAwareSql(): void
    {
        $topBuilder = (new QueryBuilder(new SqlServerQueryGrammar()))
            ->select('users.id')
            ->from('users')
            ->limit(3);

        self::assertSame(
            'SELECT TOP 3 [users].[id] FROM [users]',
            $topBuilder->toSql(),
        );

        $pagedBuilder = (new QueryBuilder(new SqlServerQueryGrammar()))
            ->select('u.id', 'u.email')
            ->from('users as u')
            ->where('u.status', '=', 'active')
            ->limit(5, 10);

        self::assertSame(
            'SELECT [u].[id], [u].[email] FROM [users] AS [u] WHERE [u].[status] = ? ORDER BY (SELECT 0) OFFSET 10 ROWS FETCH NEXT 5 ROWS ONLY',
            $pagedBuilder->toSql(),
        );
    }

    public function testSqlServerGrammarValidationMessageIsHelpful(): void
    {
        try {
            (new QueryBuilder(new SqlServerQueryGrammar()))->from('bad[name')->toSql();
            self::fail('Expected invalid SQL Server identifier exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Identifier cannot contain square brackets.', $exception->getMessage());
        }
    }
}
