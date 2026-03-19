<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use Myxa\Database\DatabaseConnectionException;
use Myxa\Database\ConnectionInterface;
use Myxa\Database\PdoConnection;
use Myxa\Database\PdoConnectionConfig;
use Myxa\Database\TransactionalConnectionInterface;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(DatabaseConnectionException::class)]
#[CoversClass(PdoConnection::class)]
final class PdoConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        PdoConnection::unregister('main', false);
    }

    protected function tearDown(): void
    {
        PdoConnection::unregister('main', false);
    }

    public function testRegisterAndGetConnectionByAlias(): void
    {
        $connection = new PdoConnection($this->makeConfig('main_db'));

        $registered = PdoConnection::register('main', $connection);

        self::assertSame($connection, $registered);
        self::assertTrue(PdoConnection::has('main'));
        self::assertSame($connection, PdoConnection::get('main'));
    }

    public function testRegisterThrowsWhenAliasAlreadyExistsAndReplaceFalse(): void
    {
        PdoConnection::register('main', new PdoConnection($this->makeConfig('db_a')));

        $this->expectException(RuntimeException::class);

        PdoConnection::register('main', new PdoConnection($this->makeConfig('db_b')));
    }

    public function testRegisterReplacesExistingAliasWhenRequested(): void
    {
        $first = new PdoConnection($this->makeConfig('db_a'));
        $second = new PdoConnection($this->makeConfig('db_b'));

        PdoConnection::register('main', $first);
        PdoConnection::register('main', $second, true);

        self::assertSame($second, PdoConnection::get('main'));
    }

    public function testGetThrowsWhenAliasIsMissing(): void
    {
        $this->expectException(RuntimeException::class);

        PdoConnection::get('missing');
    }

    public function testUnregisterRemovesAlias(): void
    {
        PdoConnection::register('main', new PdoConnection($this->makeConfig('db_a')));

        PdoConnection::unregister('main', false);

        self::assertFalse(PdoConnection::has('main'));
    }

    public function testRegisterNewCreatesAndStoresConnection(): void
    {
        $config = $this->makeConfig('db_a');

        $connection = PdoConnection::registerNew('main', $config);

        self::assertInstanceOf(PdoConnection::class, $connection);
        self::assertSame($connection, PdoConnection::get('main'));
        self::assertSame($config, $connection->getPdoConnectionConfig());
    }

    public function testRegisterFromDsnParsesAndStoresConfig(): void
    {
        $connection = PdoConnection::registerFromDsn(
            alias: 'main',
            dsn: 'mysql:dbname=app_db;host=127.0.0.1;charset=utf8mb4',
            username: 'app_user',
            password: 'secret',
        );

        self::assertSame($connection, PdoConnection::get('main'));
        self::assertSame('mysql:dbname=app_db;host=127.0.0.1;charset=utf8mb4', $connection->getPdoConnectionConfig()->getDsn());
        self::assertSame('app_user', $connection->getPdoConnectionConfig()->getUsername());
    }

    public function testConnectThrowsDatabaseConnectionExceptionForInvalidDriver(): void
    {
        if (!class_exists('PDO')) {
            $this->markTestSkipped('PDO extension is not available.');
        }

        $connection = new PdoConnection(
            new PdoConnectionConfig(
                engine: 'definitely_invalid_driver',
                database: 'app_db',
                host: '127.0.0.1',
            ),
        );

        try {
            $connection->connect();

            self::fail('Expected DatabaseConnectionException for invalid driver.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame('Failed to establish database connection.', $exception->getMessage());
            self::assertSame('definitely_invalid_driver:dbname=app_db;host=127.0.0.1', $exception->getDsn());
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testIsConnectedIsFalseBeforeConnect(): void
    {
        $connection = new PdoConnection($this->makeConfig('db_a'));

        self::assertFalse($connection->isConnected());
    }

    public function testImplementsConnectionContracts(): void
    {
        $connection = new PdoConnection($this->makeConfig('db_a'));

        self::assertInstanceOf(ConnectionInterface::class, $connection);
        self::assertInstanceOf(TransactionalConnectionInterface::class, $connection);
    }

    public function testConnectionReusesInjectedPdoAndSupportsTransactions(): void
    {
        $connection = $this->makeConnectedConnection();
        $pdo = $connection->connect();

        self::assertSame($pdo, $connection->getPdo());
        self::assertSame($connection->getPdoConnectionConfig(), $connection->getConfig());
        self::assertTrue($connection->beginTransaction());

        $connection->getPdo()->exec("INSERT INTO items (name) VALUES ('first')");

        self::assertTrue($connection->commit());
        self::assertSame(1, (int) $connection->getPdo()->query('SELECT COUNT(*) FROM items')->fetchColumn());
        self::assertTrue($connection->beginTransaction());

        $connection->getPdo()->exec("INSERT INTO items (name) VALUES ('second')");

        self::assertTrue($connection->rollBack());
        self::assertSame(1, (int) $connection->getPdo()->query('SELECT COUNT(*) FROM items')->fetchColumn());

        $connection->disconnect();

        self::assertFalse($connection->isConnected());
    }

    public function testUnregisterDisconnectsConnectionByDefault(): void
    {
        $connection = $this->makeConnectedConnection();
        PdoConnection::register('main', $connection, true);

        self::assertTrue($connection->isConnected());

        PdoConnection::unregister('main');

        self::assertFalse(PdoConnection::has('main'));
        self::assertFalse($connection->isConnected());
    }

    private function makeConfig(string $database): PdoConnectionConfig
    {
        return new PdoConnectionConfig(
            engine: 'mysql',
            database: $database,
            host: '127.0.0.1',
            username: 'app_user',
            password: 'secret',
        );
    }

    private function makeConnectedConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        $connection = new PdoConnection($this->makeConfig('db_a'));

        $pdoProperty = new ReflectionProperty(PdoConnection::class, 'pdo');
        $pdoProperty->setValue($connection, $pdo);

        return $connection;
    }
}
