<?php

declare(strict_types=1);

namespace Test\Unit\Redis;

use BadMethodCallException;
use InvalidArgumentException;
use Myxa\Application;
use Myxa\Redis\Connection\InMemoryRedisStore;
use Myxa\Redis\Connection\RedisConnection;
use Myxa\Redis\RedisManager;
use Myxa\Redis\RedisServiceProvider;
use Myxa\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(InMemoryRedisStore::class)]
#[CoversClass(RedisConnection::class)]
#[CoversClass(RedisManager::class)]
#[CoversClass(RedisServiceProvider::class)]
#[CoversClass(Redis::class)]
final class RedisTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'redis-main';

    protected function tearDown(): void
    {
        Redis::clearManager();
        RedisConnection::unregister(self::CONNECTION_ALIAS);
        RedisConnection::unregister('fallback');
    }

    public function testInMemoryStoreSupportsBasicKeyValueOperations(): void
    {
        $store = new InMemoryRedisStore();

        self::assertNull($store->get('missing'));
        self::assertTrue($store->set('name', 'myxa'));
        self::assertTrue($store->has('name'));
        self::assertSame('myxa', $store->get('name'));
        self::assertSame(1, $store->increment('visits'));
        self::assertSame(3, $store->increment('visits', 2));
        self::assertTrue($store->delete('name'));
        self::assertFalse($store->delete('name'));
        self::assertFalse($store->has('name'));
        self::assertSame(['visits' => 3], $store->all());
    }

    public function testInMemoryStoreRejectsIncrementingNonIntegerValues(): void
    {
        $store = new InMemoryRedisStore();
        $store->set('name', 'myxa');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis key "name" does not contain an integer value.');

        $store->increment('name');
    }

    public function testConnectionRegistryAndOperationsWork(): void
    {
        $connection = new RedisConnection(new InMemoryRedisStore());

        self::assertTrue($connection->setValue('count', 1));
        self::assertSame(1, $connection->getValue('count'));
        self::assertTrue($connection->hasKey('count'));
        self::assertSame(3, $connection->increment('count', 2));
        self::assertTrue($connection->delete('count'));
        self::assertFalse($connection->hasKey('count'));
        self::assertInstanceOf(InMemoryRedisStore::class, $connection->store());

        RedisConnection::register(self::CONNECTION_ALIAS, $connection, true);
        self::assertTrue(RedisConnection::has(self::CONNECTION_ALIAS));
        self::assertSame($connection, RedisConnection::get(self::CONNECTION_ALIAS));
    }

    public function testConnectionRegistryRejectsDuplicatesAndMissingAliases(): void
    {
        $connection = new RedisConnection(new InMemoryRedisStore());
        RedisConnection::register(self::CONNECTION_ALIAS, $connection, true);

        try {
            RedisConnection::register(self::CONNECTION_ALIAS, $connection);
            self::fail('Expected duplicate alias exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Connection alias "redis-main" is already registered.', $exception->getMessage());
        }

        RedisConnection::unregister(self::CONNECTION_ALIAS);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection alias "redis-main" is not registered.');

        RedisConnection::get(self::CONNECTION_ALIAS);
    }

    public function testManagerResolvesConnectionsAndCommands(): void
    {
        $manager = new RedisManager(
            self::CONNECTION_ALIAS,
            new RedisConnection(new InMemoryRedisStore()),
        );

        self::assertSame(self::CONNECTION_ALIAS, $manager->getDefaultConnection());
        self::assertTrue($manager->set('name', 'framework'));
        self::assertSame('framework', $manager->get('name'));
        self::assertTrue($manager->has('name'));
        self::assertTrue($manager->delete('name'));
        self::assertFalse($manager->has('name'));
    }

    public function testManagerConstructorSupportsConnectionFactoryForDefaultAlias(): void
    {
        $manager = new RedisManager(
            self::CONNECTION_ALIAS,
            static fn (): RedisConnection => new RedisConnection(new InMemoryRedisStore()),
        );

        self::assertInstanceOf(RedisConnection::class, $manager->connection());
        self::assertTrue($manager->set('count', 5));
        self::assertSame(7, $manager->increment('count', 2));
    }

    public function testManagerSupportsFactoriesFallbacksAndValidation(): void
    {
        $fallback = new RedisConnection(new InMemoryRedisStore());
        RedisConnection::register('fallback', $fallback, true);

        $manager = new RedisManager(' fallback ');
        self::assertSame($fallback, $manager->connection());

        $manager->addConnection('factory', fn (): RedisConnection => new RedisConnection(new InMemoryRedisStore()));
        self::assertInstanceOf(RedisConnection::class, $manager->connection('factory'));

        try {
            new RedisManager(' ');
            self::fail('Expected invalid alias exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Connection alias cannot be empty.', $exception->getMessage());
        }

        try {
            $manager->addConnection('broken', static fn (RedisManager $one, string $two): RedisConnection => throw new RuntimeException('nope'));
            self::fail('Expected invalid factory signature exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Connection factory for alias "broken" must accept zero or one parameter.',
                $exception->getMessage(),
            );
        }
    }

    public function testManagerRejectsMissingConnectionsAndInvalidFactoryResults(): void
    {
        $manager = new RedisManager(self::CONNECTION_ALIAS);

        try {
            $manager->connection('missing');
            self::fail('Expected missing connection exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Connection alias "missing" is not registered.', $exception->getMessage());
        }

        $manager->addConnection('broken', static fn () => new \stdClass(), true);

        $this->expectException(\TypeError::class);

        $manager->connection('broken');
    }

    public function testServiceProviderAndFacadeBootstrapRedisManager(): void
    {
        $app = new Application();
        $app->register(new RedisServiceProvider(
            connections: [
                self::CONNECTION_ALIAS => new RedisConnection(new InMemoryRedisStore()),
            ],
            defaultConnection: self::CONNECTION_ALIAS,
        ));
        $app->boot();

        $manager = $app->make(RedisManager::class);

        self::assertSame($manager, $app->make('redis'));
        self::assertSame($manager, Redis::getManager());
        self::assertTrue(Redis::set('count', 10));
        self::assertSame(12, Redis::increment('count', 2));
        self::assertSame(12, Redis::get('count'));
        self::assertInstanceOf(RedisConnection::class, Redis::connection());
    }

    public function testFacadeThrowsClearExceptionForUnknownMethod(): void
    {
        Redis::setManager(new RedisManager(
            self::CONNECTION_ALIAS,
            new RedisConnection(new InMemoryRedisStore()),
        ));

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Redis facade method "foobar" is not supported.');

        Redis::foobar();
    }
}
