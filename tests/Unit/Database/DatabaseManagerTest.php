<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use InvalidArgumentException;
use Myxa\Application;
use Myxa\Database\DatabaseException;
use Myxa\Database\DatabaseManager;
use Myxa\Database\PdoConnection;
use Myxa\Database\PdoConnectionConfig;
use Myxa\Database\QueryBuilder;
use Myxa\Database\RawExpression;
use Myxa\Database\DatabaseServiceProvider;
use Myxa\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;

#[CoversClass(DB::class)]
#[CoversClass(DatabaseManager::class)]
#[CoversClass(DatabaseServiceProvider::class)]
final class DatabaseManagerTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'main';

    protected function tearDown(): void
    {
        DB::clearManager();
        PdoConnection::unregister(self::CONNECTION_ALIAS, false);
        PdoConnection::unregister('fallback', false);
        PdoConnection::unregister('replaceable', false);
        PdoConnection::unregister('duplicate', false);
    }

    public function testManagerExecutesQueriesUsingManagedConnection(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeInMemoryConnection());

        $rows = $manager->select(
            'SELECT id, email FROM users WHERE status = ? ORDER BY id ASC',
            ['active'],
        );

        self::assertCount(2, $rows);
        self::assertSame('john@example.com', $rows[0]['email']);
        self::assertSame('jane@example.com', $rows[1]['email']);
    }

    public function testManagerResolvesZeroArgumentConnectionFactory(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, fn (): PdoConnection => $this->makeInMemoryConnection());

        $rows = $manager->select(
            'SELECT COUNT(*) AS total FROM users WHERE status = ?',
            ['active'],
        );

        self::assertSame(2, (int) $rows[0]['total']);
    }

    public function testManagerResolvesManagerAwareConnectionFactory(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, function (DatabaseManager $resolvedManager) use ($manager): PdoConnection {
            self::assertSame($manager, $resolvedManager);

            return $this->makeInMemoryConnection();
        });

        $rows = $manager->select(
            'SELECT COUNT(*) AS total FROM users WHERE status = ?',
            ['inactive'],
        );

        self::assertSame(1, (int) $rows[0]['total']);
    }

    public function testManagerRejectsConnectionFactoryWithMoreThanOneParameter(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Connection factory for alias "main" must accept zero or one parameter.');

        $manager->addConnection(
            self::CONNECTION_ALIAS,
            static fn (DatabaseManager $db, string $alias): PdoConnection => throw new \LogicException('not reached'),
        );
    }

    public function testDatabaseServiceProviderBootstrapsManagerAndFacade(): void
    {
        $app = new Application();
        $app->register(new DatabaseServiceProvider(
            connections: [self::CONNECTION_ALIAS => $this->makeInMemoryConnection()],
            defaultConnection: self::CONNECTION_ALIAS,
        ));

        $app->boot();

        $manager = $app->make(DatabaseManager::class);
        $rows = DB::select(
            'SELECT COUNT(*) AS total FROM users WHERE status = ?',
            ['active'],
        );

        self::assertSame($manager, $app->make('db'));
        self::assertSame($manager, DB::getManager());
        self::assertSame(2, (int) $rows[0]['total']);
    }

    public function testManagerExposesHelpersAndTracksDefaultConnection(): void
    {
        $manager = new DatabaseManager(' main ');
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeInMemoryConnection());
        $manager->setDefaultConnection(self::CONNECTION_ALIAS);

        self::assertSame(self::CONNECTION_ALIAS, $manager->getDefaultConnection());
        self::assertInstanceOf(QueryBuilder::class, $manager->query());

        $raw = $manager->raw('NOW()');

        self::assertInstanceOf(RawExpression::class, $raw);
        self::assertSame('NOW()', $raw->getValue());
    }

    public function testManagerUsesRegisteredFallbackConnectionsAndHasConnectionChecks(): void
    {
        $fallback = $this->makeInMemoryConnection();
        PdoConnection::register('fallback', $fallback, true);

        $manager = new DatabaseManager('fallback');

        self::assertTrue($manager->hasConnection('fallback'));
        self::assertFalse($manager->hasConnection('missing'));
        self::assertSame($fallback, $manager->connection());
        self::assertSame($fallback->getPdo(), $manager->pdo());
    }

    public function testManagerAllowsReplacingExistingManagedConnection(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $first = $this->makeInMemoryConnection();
        $second = $this->makeInMemoryConnection();

        $manager->addConnection('replaceable', $first);
        $manager->addConnection('replaceable', $second, true);

        self::assertSame($second, $manager->connection('replaceable'));
    }

    public function testManagerBuildsConfiguredConnectionsLazily(): void
    {
        $config = new PdoConnectionConfig(
            engine: 'mysql',
            database: 'configured_db',
            host: '127.0.0.1',
            username: 'app_user',
            password: 'secret',
        );

        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $manager->addConnection('configured', $config);

        $connection = $manager->connection('configured');

        self::assertTrue($manager->hasConnection('configured'));
        self::assertInstanceOf(PdoConnection::class, $connection);
        self::assertSame($config, $connection->getConfig());
    }

    public function testManagerExecutesDirectSqlHelpersAndAffectingStatements(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeInMemoryConnection());

        self::assertSame(
            "SELECT id FROM users WHERE status = 'active' AND id = 1",
            $manager->toRawSql(
                'SELECT id FROM users WHERE status = :status AND id = :id',
                ['status' => 'active', 'id' => 1],
            ),
        );
        self::assertTrue($manager->statement(
            'CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)',
        ));

        $insertedId = $manager->insert('INSERT INTO notes (title) VALUES (?)', ['first note']);

        self::assertSame(1, $insertedId);
        self::assertSame(1, $manager->update(
            'UPDATE users SET status = ? WHERE id = ?',
            ['pending', 1],
        ));
        self::assertSame('pending', $manager->select(
            'SELECT status FROM users WHERE id = ?',
            [1],
        )[0]['status']);
        self::assertSame(1, $manager->delete(
            'DELETE FROM users WHERE status = ?',
            ['inactive'],
        ));
    }

    public function testManagerDirectTransactionHelpersCommitRollBackAndTransactionCallbacks(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeInMemoryConnection());

        self::assertTrue($manager->beginTransaction());
        self::assertTrue($manager->statement(
            'UPDATE users SET status = ? WHERE id = ?',
            ['pending', 1],
        ));
        self::assertTrue($manager->commit());
        self::assertSame('pending', $manager->select(
            'SELECT status FROM users WHERE id = ?',
            [1],
        )[0]['status']);

        self::assertTrue($manager->beginTransaction());
        self::assertTrue($manager->statement(
            'UPDATE users SET status = ? WHERE id = ?',
            ['archived', 1],
        ));
        self::assertTrue($manager->rollBack());
        self::assertSame('pending', $manager->select(
            'SELECT status FROM users WHERE id = ?',
            [1],
        )[0]['status']);

        $result = $manager->transaction(function () use ($manager): string {
            $manager->statement(
                'UPDATE users SET status = ? WHERE id = ?',
                ['archived', 3],
            );

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame('archived', $manager->select(
            'SELECT status FROM users WHERE id = ?',
            [3],
        )[0]['status']);
    }

    public function testManagerTransactionRollsBackAndRethrowsCallbackExceptions(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeInMemoryConnection());

        try {
            $manager->transaction(function () use ($manager): void {
                $manager->statement(
                    'UPDATE users SET status = ? WHERE id = ?',
                    ['archived', 2],
                );

                throw new RuntimeException('boom');
            });

            self::fail('Expected RuntimeException from transaction callback.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame('inactive', $manager->select(
            'SELECT status FROM users WHERE id = ?',
            [2],
        )[0]['status']);
    }

    public function testManagerWrapsPdoFailuresAndRejectsInvalidBindingValues(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeInMemoryConnection());

        try {
            $manager->statement('INSERT INTO missing_table (email) VALUES (?)', ['john@example.com']);

            self::fail('Expected DatabaseException for invalid SQL.');
        } catch (DatabaseException $exception) {
            self::assertSame('Database query failed on connection "main".', $exception->getMessage());
            self::assertSame('INSERT INTO missing_table (email) VALUES (?)', $exception->getSql());
            self::assertSame(self::CONNECTION_ALIAS, $exception->getConnection());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Binding value must be a scalar or null.');

        $manager->select('SELECT id FROM users WHERE id = ?', [new stdClass()]);
    }

    public function testManagerRejectsInvalidAliasesAndInvalidFactoryReturns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Connection alias cannot be empty.');

        new DatabaseManager(' ');
    }

    public function testManagerRejectsInvalidFactoryResultsAndMissingConnections(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $manager->addConnection('broken', static fn (): stdClass => new stdClass());

        try {
            $manager->connection('broken');
            self::fail('Expected TypeError for invalid connection factory result.');
        } catch (\TypeError $exception) {
            self::assertStringContainsString(PdoConnection::class, $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection alias "missing" is not registered.');

        $manager->connection('missing');
    }

    public function testManagerRejectsDuplicateConnectionAliasWithoutReplaceFlag(): void
    {
        $manager = new DatabaseManager(self::CONNECTION_ALIAS);
        $manager->addConnection('duplicate', $this->makeInMemoryConnection());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection alias "duplicate" is already registered.');

        $manager->addConnection('duplicate', $this->makeInMemoryConnection());
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
