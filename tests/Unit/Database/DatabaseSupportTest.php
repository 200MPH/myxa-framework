<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use InvalidArgumentException;
use Myxa\Database\DatabaseConnectionException;
use Myxa\Database\PdoConnectionConfig;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdoConnectionConfig::class)]
#[CoversClass(DatabaseConnectionException::class)]
final class DatabaseSupportTest extends TestCase
{
    public function testPdoConnectionConfigCanBeBuiltFromDsnAndExposeProperties(): void
    {
        $config = PdoConnectionConfig::fromDsn(
            'mysql:dbname=myxa;host=127.0.0.1;port=3306;charset=utf8mb4;unix_socket=/tmp/mysql.sock',
            'root',
            'secret',
            [123 => 'value'],
        );

        self::assertSame(
            'mysql:dbname=myxa;host=127.0.0.1;port=3306;charset=utf8mb4;unix_socket=/tmp/mysql.sock',
            $config->getDsn(),
        );
        self::assertSame('root', $config->getUsername());
        self::assertSame('secret', $config->getPassword());
        self::assertSame([123 => 'value'], $config->getOptions());
    }

    public function testPdoConnectionConfigRejectsInvalidInput(): void
    {
        try {
            PdoConnectionConfig::fromDsn('');
            self::fail('Expected DSN validation exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('DSN cannot be empty.', $exception->getMessage());
        }

        try {
            PdoConnectionConfig::fromDsn('broken');
            self::fail('Expected DSN format validation exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('DSN must be in format "engine:key=value;...".', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Engine, database and host are required.');

        new PdoConnectionConfig('', 'db', 'host');
    }

    public function testDatabaseConnectionExceptionWrapsPdoFailureContext(): void
    {
        $pdoException = new PDOException('boom', 500);
        $exception = DatabaseConnectionException::fromPdoException($pdoException, 'sqlite::memory:');

        self::assertSame('Failed to establish database connection.', $exception->getMessage());
        self::assertSame('sqlite::memory:', $exception->getDsn());
        self::assertSame(500, $exception->getDriverCode());
        self::assertSame($pdoException, $exception->getPrevious());
    }
}
