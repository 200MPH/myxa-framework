<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use InvalidArgumentException;
use Myxa\Application;
use Myxa\Database\DatabaseManager;
use Myxa\Database\DatabaseServiceProvider;
use Myxa\Database\PdoConnection;
use Myxa\Database\PdoConnectionConfig;
use Myxa\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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
