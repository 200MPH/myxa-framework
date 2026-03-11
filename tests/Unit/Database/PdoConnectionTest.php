<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use Myxa\Database\PdoConnection;
use Myxa\Database\PdoConnectionConfig;
use Myxa\Database\ConnectionInterface;
use Myxa\Database\TransactionalConnectionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testConnectThrowsRuntimeExceptionForInvalidDriver(): void
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

        $this->expectException(RuntimeException::class);

        $connection->connect();
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
}
