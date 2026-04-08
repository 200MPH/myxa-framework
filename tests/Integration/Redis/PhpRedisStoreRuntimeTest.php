<?php

declare(strict_types=1);

namespace Test\Integration\Redis;

use Myxa\Redis\Connection\PhpRedisStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PhpRedisStoreRuntimeTest extends TestCase
{
    private ?PhpRedisStore $store = null;

    protected function setUp(): void
    {
        if (getenv('MYXA_REDIS_TEST_ENABLED') !== '1') {
            self::markTestSkipped('Redis runtime test is disabled.');
        }

        $host = getenv('MYXA_REDIS_TEST_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('MYXA_REDIS_TEST_PORT') ?: '6379');
        $database = (int) (getenv('MYXA_REDIS_TEST_DATABASE') ?: '0');

        $this->store = new PhpRedisStore(
            host: $host,
            port: $port,
            database: $database,
        );
        $this->store->flush();
    }

    public function testPhpRedisStoreSupportsBasicCommandsAgainstRealRedis(): void
    {
        self::assertTrue($this->store->set('name', 'myxa'));
        self::assertSame('myxa', $this->store->get('name'));
        self::assertTrue($this->store->has('name'));

        self::assertTrue($this->store->set('count', 1));
        self::assertSame(3, $this->store->increment('count', 2));
        self::assertSame(3, $this->store->get('count'));

        self::assertTrue($this->store->set('flag', true));
        self::assertTrue($this->store->get('flag'));

        self::assertTrue($this->store->set('empty', null));
        self::assertNull($this->store->get('empty'));

        self::assertTrue($this->store->delete('name'));
        self::assertFalse($this->store->delete('name'));
        self::assertFalse($this->store->has('name'));
    }

    public function testPhpRedisStoreRejectsIncrementingNonIntegerPayload(): void
    {
        $this->store->set('name', 'myxa');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis key "name" does not contain an integer value.');

        $this->store->increment('name');
    }
}
