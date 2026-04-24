<?php

declare(strict_types=1);

namespace Test\Unit\Redis;

use BadMethodCallException;
use InvalidArgumentException;
use Myxa\Application;
use Myxa\Redis\Connection\InMemoryRedisStore;
use Myxa\Redis\Connection\PhpRedisStore;
use Myxa\Redis\Connection\RedisConnection;
use Myxa\Redis\RedisManager;
use Myxa\Redis\RedisServiceProvider;
use Myxa\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(InMemoryRedisStore::class)]
#[CoversClass(PhpRedisStore::class)]
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

    public function testPhpRedisStoreEncodesDecodesAndProxiesClientOperations(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis extension is not available.');
        }

        $client = $this->getMockBuilder(\Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'set', 'del', 'exists', 'flushDB'])
            ->getMock();
        $client->method('get')->willReturnMap([
            ['missing', false],
            ['name', '{"type":"string","value":"myxa"}'],
            ['count', '{"type":"int","value":2}'],
            ['flag', '{"type":"bool","value":true}'],
            ['ratio', '{"type":"float","value":1.5}'],
            ['empty', '{"type":"null","value":null}'],
        ]);
        $client->method('set')->willReturn(true);
        $client->method('del')->willReturn(1);
        $client->method('exists')->willReturn(1);

        $store = new PhpRedisStore();
        $this->injectPhpRedisClient($store, $client);

        self::assertNull($store->get('missing'));
        self::assertSame('myxa', $store->get('name'));
        self::assertSame(2, $store->get('count'));
        self::assertTrue($store->get('flag'));
        self::assertSame(1.5, $store->get('ratio'));
        self::assertNull($store->get('empty'));
        self::assertTrue($store->set('next', 3));
        self::assertTrue($store->set('enabled', true));
        self::assertTrue($store->set('ratio', 1.5));
        self::assertTrue($store->set('nothing', null));
        self::assertTrue($store->delete('name'));
        self::assertTrue($store->has('name'));
        self::assertSame(4, $store->increment('count', 2));
        $store->flush();
    }

    public function testPhpRedisStoreConnectsAuthenticatesAndSelectsDatabase(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis extension is not available.');
        }

        $client = $this->getMockBuilder(\Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['connect', 'auth', 'select'])
            ->getMock();
        $client->expects(self::once())
            ->method('connect')
            ->with('redis-host', 6380, 1.5)
            ->willReturn(true);
        $client->expects(self::once())
            ->method('auth')
            ->with('secret')
            ->willReturn(true);
        $client->expects(self::once())
            ->method('select')
            ->with(2)
            ->willReturn(true);

        $store = new PhpRedisStore(
            host: 'redis-host',
            port: 6380,
            timeout: 1.5,
            database: 2,
            password: 'secret',
            clientFactory: static fn (): \Redis => $client,
        );

        self::assertSame($client, $store->client());
        self::assertSame($client, $store->client());
    }

    public function testPhpRedisStoreReportsConnectionSetupFailures(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis extension is not available.');
        }

        $badFactory = new PhpRedisStore(clientFactory: static fn (): mixed => new \stdClass());

        try {
            $badFactory->client();
            self::fail('Expected invalid Redis client factory exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(sprintf('Redis client factory must return %s.', \Redis::class), $exception->getMessage());
        }

        $authClient = $this->getMockBuilder(\Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['connect', 'auth'])
            ->getMock();
        $authClient->method('connect')->willReturn(true);
        $authClient->method('auth')->willReturn(false);

        try {
            (new PhpRedisStore(password: 'bad', clientFactory: static fn (): \Redis => $authClient))->client();
            self::fail('Expected Redis auth exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to authenticate with Redis.', $exception->getMessage());
        }

        $selectClient = $this->getMockBuilder(\Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['connect', 'select'])
            ->getMock();
        $selectClient->method('connect')->willReturn(true);
        $selectClient->method('select')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to select Redis database 2.');

        (new PhpRedisStore(database: 2, clientFactory: static fn (): \Redis => $selectClient))->client();
    }

    public function testPhpRedisStoreRejectsInvalidPayloads(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis extension is not available.');
        }

        foreach (
            [
                'non-string' => 123,
                'invalid' => '{"value":"missing-type"}',
                'unknown' => '{"type":"other","value":"x"}',
                'not-integer' => '{"type":"string","value":"myxa"}',
            ] as $key => $payload
        ) {
            $client = $this->getMockBuilder(\Redis::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['get'])
                ->getMock();
            $client->method('get')->willReturn($payload);

            $store = new PhpRedisStore();
            $this->injectPhpRedisClient($store, $client);

            try {
                $key === 'not-integer' ? $store->increment($key) : $store->get($key);
                self::fail('Expected invalid Redis payload exception.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(sprintf('Redis key "%s"', $key), $exception->getMessage());
            }
        }
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
        self::assertTrue($manager->hasConnection('fallback'));

        $manager->addConnection('factory', fn (RedisManager $redis): RedisConnection => new RedisConnection(new InMemoryRedisStore()));
        $manager->setDefaultConnection('factory');
        self::assertSame('factory', $manager->getDefaultConnection());
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

        try {
            $manager->addConnection('factory', new RedisConnection(new InMemoryRedisStore()));
            self::fail('Expected duplicate connection exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Connection alias "factory" is already registered.', $exception->getMessage());
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

        $manager->addConnection('broken', static fn (): mixed => new \stdClass(), true);

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

    private function injectPhpRedisClient(PhpRedisStore $store, \Redis $client): void
    {
        $property = new ReflectionProperty(PhpRedisStore::class, 'client');
        $property->setValue($store, $client);
    }
}
