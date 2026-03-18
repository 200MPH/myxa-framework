<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use InvalidArgumentException;
use Myxa\Database\DatabaseException;
use Myxa\Database\PdoConnection;
use Myxa\Database\PdoConnectionConfig;
use Myxa\Database\QueryBuilder;
use Myxa\Database\RawExpression;
use Myxa\Database\SqlInterpolator;
use Myxa\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;

#[CoversClass(DB::class)]
#[CoversClass(DatabaseException::class)]
#[CoversClass(RawExpression::class)]
#[CoversClass(SqlInterpolator::class)]
final class DBTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'db-test';

    protected function setUp(): void
    {
        PdoConnection::unregister(self::CONNECTION_ALIAS, false);
        PdoConnection::register(self::CONNECTION_ALIAS, $this->makeInMemoryConnection(), true);
    }

    protected function tearDown(): void
    {
        PdoConnection::unregister(self::CONNECTION_ALIAS);
    }

    public function testSelectReturnsRowsForPositionalBindings(): void
    {
        $rows = DB::select(
            'SELECT id, email FROM users WHERE status = ? ORDER BY id ASC',
            ['active'],
            self::CONNECTION_ALIAS,
        );

        self::assertCount(2, $rows);
        self::assertSame(1, $rows[0]['id']);
        self::assertSame('john@example.com', $rows[0]['email']);
        self::assertSame(3, $rows[1]['id']);
        self::assertSame('jane@example.com', $rows[1]['email']);
    }

    public function testSelectSupportsNamedBindings(): void
    {
        $rows = DB::select(
            'SELECT id FROM users WHERE status = :status',
            ['status' => 'inactive'],
            self::CONNECTION_ALIAS,
        );

        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]['id']);
    }

    public function testStatementExecutesCustomSql(): void
    {
        $executed = DB::statement(
            'CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)',
            [],
            self::CONNECTION_ALIAS,
        );

        self::assertTrue($executed);
        self::assertTrue(
            DB::statement(
                'INSERT INTO notes (title) VALUES (?)',
                ['first note'],
                self::CONNECTION_ALIAS,
            ),
        );
        self::assertSame('first note', DB::select(
            'SELECT title FROM notes LIMIT 1',
            [],
            self::CONNECTION_ALIAS,
        )[0]['title']);
    }

    public function testInsertReturnsLastInsertIdAndPersistsRow(): void
    {
        $insertedId = DB::insert(
            'INSERT INTO users (email, status) VALUES (?, ?)',
            ['new@example.com', 'pending'],
            self::CONNECTION_ALIAS,
        );

        self::assertSame(4, $insertedId);
        self::assertSame(
            'pending',
            DB::select(
                'SELECT status FROM users WHERE id = ?',
                [$insertedId],
                self::CONNECTION_ALIAS,
            )[0]['status'],
        );
    }

    public function testUpdateReturnsChangedRows(): void
    {
        $affectedRows = DB::update(
            'UPDATE users SET status = ? WHERE status = ?',
            ['archived', 'inactive'],
            self::CONNECTION_ALIAS,
        );

        self::assertSame(1, $affectedRows);
    }

    public function testDeleteReturnsChangedRows(): void
    {
        $affectedRows = DB::delete(
            'DELETE FROM users WHERE status = ?',
            ['inactive'],
            self::CONNECTION_ALIAS,
        );

        self::assertSame(1, $affectedRows);
        self::assertSame(
            2,
            (int) DB::select(
                'SELECT COUNT(*) AS total FROM users',
                [],
                self::CONNECTION_ALIAS,
            )[0]['total'],
        );
    }

    public function testRawReturnsExpressionObject(): void
    {
        $raw = DB::raw('COUNT(*) AS aggregate');

        self::assertInstanceOf(RawExpression::class, $raw);
        self::assertSame('COUNT(*) AS aggregate', $raw->getValue());
        self::assertSame('COUNT(*) AS aggregate', (string) $raw);
    }

    public function testConnectionAndPdoReturnRegisteredObjects(): void
    {
        $connection = DB::connection(self::CONNECTION_ALIAS);

        self::assertInstanceOf(PdoConnection::class, $connection);
        self::assertInstanceOf(PDO::class, DB::pdo(self::CONNECTION_ALIAS));
        self::assertSame($connection->getPdo(), DB::pdo(self::CONNECTION_ALIAS));
    }

    public function testQueryReturnsQueryBuilderInstance(): void
    {
        self::assertInstanceOf(QueryBuilder::class, DB::query());
    }

    public function testToRawSqlInterpolatesPositionalBindings(): void
    {
        $sql = DB::toRawSql(
            'SELECT id FROM users WHERE status = ? AND email = ?',
            ['active', "o'reilly@example.com"],
            self::CONNECTION_ALIAS,
        );

        self::assertSame(
            "SELECT id FROM users WHERE status = 'active' AND email = 'o''reilly@example.com'",
            $sql,
        );
    }

    public function testToRawSqlInterpolatesNamedBindings(): void
    {
        $sql = DB::toRawSql(
            'SELECT id FROM users WHERE status = :status AND id > :minimum_id',
            ['status' => 'inactive', ':minimum_id' => 1],
            self::CONNECTION_ALIAS,
        );

        self::assertSame(
            "SELECT id FROM users WHERE status = 'inactive' AND id > 1",
            $sql,
        );
    }

    public function testToRawSqlDoesNotReplacePlaceholdersInsideQuotedStrings(): void
    {
        $sql = DB::toRawSql(
            "SELECT '?' AS marker, ':status' AS text, status FROM users WHERE status = :status",
            ['status' => 'active'],
            self::CONNECTION_ALIAS,
        );

        self::assertSame(
            "SELECT '?' AS marker, ':status' AS text, status FROM users WHERE status = 'active'",
            $sql,
        );
    }

    public function testBeginTransactionAndCommitPersistChanges(): void
    {
        self::assertTrue(DB::beginTransaction(self::CONNECTION_ALIAS));
        self::assertTrue(
            DB::statement(
                'UPDATE users SET status = ? WHERE id = ?',
                ['pending', 1],
                self::CONNECTION_ALIAS,
            ),
        );
        self::assertTrue(DB::commit(self::CONNECTION_ALIAS));

        $rows = DB::select(
            'SELECT status FROM users WHERE id = ?',
            [1],
            self::CONNECTION_ALIAS,
        );

        self::assertSame('pending', $rows[0]['status']);
    }

    public function testBeginTransactionAndRollBackDiscardChanges(): void
    {
        self::assertTrue(DB::beginTransaction(self::CONNECTION_ALIAS));
        self::assertTrue(
            DB::statement(
                'UPDATE users SET status = ? WHERE id = ?',
                ['pending', 1],
                self::CONNECTION_ALIAS,
            ),
        );
        self::assertTrue(DB::rollBack(self::CONNECTION_ALIAS));

        $rows = DB::select(
            'SELECT status FROM users WHERE id = ?',
            [1],
            self::CONNECTION_ALIAS,
        );

        self::assertSame('active', $rows[0]['status']);
    }

    public function testTransactionCommitsAndReturnsCallbackValue(): void
    {
        $result = DB::transaction(function () {
            DB::statement(
                'UPDATE users SET status = ? WHERE id = ?',
                ['archived', 3],
                self::CONNECTION_ALIAS,
            );

            return 'done';
        }, self::CONNECTION_ALIAS);

        self::assertSame('done', $result);
        self::assertSame(
            'archived',
            DB::select(
                'SELECT status FROM users WHERE id = ?',
                [3],
                self::CONNECTION_ALIAS,
            )[0]['status'],
        );
    }

    public function testTransactionRollsBackAndRethrowsOnException(): void
    {
        try {
            DB::transaction(function (): void {
                DB::statement(
                    'UPDATE users SET status = ? WHERE id = ?',
                    ['archived', 3],
                    self::CONNECTION_ALIAS,
                );

                throw new RuntimeException('boom');
            }, self::CONNECTION_ALIAS);

            self::fail('Expected RuntimeException from transaction callback.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame(
            'active',
            DB::select(
                'SELECT status FROM users WHERE id = ?',
                [3],
                self::CONNECTION_ALIAS,
            )[0]['status'],
        );
    }

    public function testSelectThrowsForInvalidBindingValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DB::select(
            'SELECT id FROM users WHERE id = ?',
            [new stdClass()],
            self::CONNECTION_ALIAS,
        );
    }

    public function testStatementWrapsPdoFailuresInDatabaseException(): void
    {
        try {
            DB::statement(
                'INSERT INTO missing_table (email) VALUES (?)',
                ['john@example.com'],
                self::CONNECTION_ALIAS,
            );

            self::fail('Expected DatabaseException for invalid SQL.');
        } catch (DatabaseException $exception) {
            self::assertSame('Database query failed on connection "db-test".', $exception->getMessage());
            self::assertSame('INSERT INTO missing_table (email) VALUES (?)', $exception->getSql());
            self::assertSame(self::CONNECTION_ALIAS, $exception->getConnection());
            self::assertNotNull($exception->getPrevious());
        }
    }

    private function makeInMemoryConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(
            'CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'email TEXT NOT NULL, '
            . 'status TEXT NOT NULL'
            . ')',
        );
        $pdo->exec(
            "INSERT INTO users (email, status) VALUES "
            . "('john@example.com', 'active'), "
            . "('anna@example.com', 'inactive'), "
            . "('jane@example.com', 'active')",
        );

        $connection = new PdoConnection(
            new PdoConnectionConfig(
                engine: 'mysql',
                database: 'placeholder',
                host: '127.0.0.1',
            ),
        );

        $pdoProperty = new ReflectionProperty(PdoConnection::class, 'pdo');
        $pdoProperty->setValue($connection, $pdo);

        return $connection;
    }
}
