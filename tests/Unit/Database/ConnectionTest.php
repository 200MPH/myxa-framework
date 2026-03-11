<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use Myxa\Database\Connection;
use Myxa\Database\ConnectionConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::unregister('main', false);
    }

    protected function tearDown(): void
    {
        Connection::unregister('main', false);
    }

    public function testRegisterAndGetConnectionByAlias(): void
    {
        $connection = new Connection($this->makeConfig('main_db'));

        $registered = Connection::register('main', $connection);

        self::assertSame($connection, $registered);
        self::assertTrue(Connection::has('main'));
        self::assertSame($connection, Connection::get('main'));
    }

    public function testRegisterThrowsWhenAliasAlreadyExistsAndReplaceFalse(): void
    {
        Connection::register('main', new Connection($this->makeConfig('db_a')));

        $this->expectException(RuntimeException::class);

        Connection::register('main', new Connection($this->makeConfig('db_b')));
    }

    public function testRegisterReplacesExistingAliasWhenRequested(): void
    {
        $first = new Connection($this->makeConfig('db_a'));
        $second = new Connection($this->makeConfig('db_b'));

        Connection::register('main', $first);
        Connection::register('main', $second, true);

        self::assertSame($second, Connection::get('main'));
    }

    public function testGetThrowsWhenAliasIsMissing(): void
    {
        $this->expectException(RuntimeException::class);

        Connection::get('missing');
    }

    public function testUnregisterRemovesAlias(): void
    {
        Connection::register('main', new Connection($this->makeConfig('db_a')));

        Connection::unregister('main', false);

        self::assertFalse(Connection::has('main'));
    }

    public function testRegisterNewCreatesAndStoresConnection(): void
    {
        $config = $this->makeConfig('db_a');

        $connection = Connection::registerNew('main', $config);

        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame($connection, Connection::get('main'));
        self::assertSame($config, $connection->getConfig());
    }

    public function testConnectThrowsRuntimeExceptionForInvalidDriver(): void
    {
        if (!class_exists('PDO')) {
            $this->markTestSkipped('PDO extension is not available.');
        }

        $connection = new Connection(
            new ConnectionConfig(
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
        $connection = new Connection($this->makeConfig('db_a'));

        self::assertFalse($connection->isConnected());
    }

    private function makeConfig(string $database): ConnectionConfig
    {
        return new ConnectionConfig(
            engine: 'mysql',
            database: $database,
            host: '127.0.0.1',
            username: 'app_user',
            password: 'secret',
        );
    }
}
