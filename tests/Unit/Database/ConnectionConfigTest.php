<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use InvalidArgumentException;
use Myxa\Database\ConnectionConfig;
use PHPUnit\Framework\TestCase;

final class ConnectionConfigTest extends TestCase
{
    public function testBuildsDsnFromConstructorParts(): void
    {
        $config = new ConnectionConfig(
            engine: 'mysql',
            database: 'app_db',
            host: '127.0.0.1',
            port: 3306,
            charset: 'utf8mb4',
            username: 'app_user',
            password: 'secret',
            options: [1 => true],
            dsnExtras: ['unix_socket' => '/tmp/mysql.sock'],
        );

        self::assertSame(
            'mysql:dbname=app_db;host=127.0.0.1;port=3306;charset=utf8mb4;unix_socket=/tmp/mysql.sock',
            $config->getDsn(),
        );
        self::assertSame('app_user', $config->getUsername());
        self::assertSame('secret', $config->getPassword());
        self::assertSame([1 => true], $config->getOptions());
    }

    public function testThrowsWhenRequiredPartsAreMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionConfig(
            engine: '',
            database: 'app_db',
            host: '127.0.0.1',
        );
    }

    public function testFromDsnParsesKnownAndExtraSegments(): void
    {
        $config = ConnectionConfig::fromDsn(
            dsn: 'mysql:dbname=app_db;host=127.0.0.1;port=3306;charset=utf8mb4;unix_socket=/tmp/mysql.sock',
            username: 'app_user',
            password: 'secret',
            options: [2 => 'value'],
        );

        self::assertSame(
            'mysql:dbname=app_db;host=127.0.0.1;port=3306;charset=utf8mb4;unix_socket=/tmp/mysql.sock',
            $config->getDsn(),
        );
        self::assertSame('app_user', $config->getUsername());
        self::assertSame('secret', $config->getPassword());
        self::assertSame([2 => 'value'], $config->getOptions());
    }

    public function testFromDsnThrowsOnInvalidInput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConnectionConfig::fromDsn('not-a-valid-dsn');
    }
}
