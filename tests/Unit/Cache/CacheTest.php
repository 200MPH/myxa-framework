<?php

declare(strict_types=1);

namespace Test\Unit\Cache;

use BadMethodCallException;
use InvalidArgumentException;
use Myxa\Application;
use Myxa\Cache\CacheManager;
use Myxa\Cache\CacheServiceProvider;
use Myxa\Cache\Store\FileCacheStore;
use Myxa\Cache\Store\RedisCacheStore;
use Myxa\Redis\Connection\InMemoryRedisStore;
use Myxa\Redis\Connection\RedisConnection;
use Myxa\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CacheManager::class)]
#[CoversClass(CacheServiceProvider::class)]
#[CoversClass(FileCacheStore::class)]
#[CoversClass(RedisCacheStore::class)]
#[CoversClass(Cache::class)]
final class CacheTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/myxa-cache-tests-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        Cache::clearManager();

        foreach (glob($this->cachePath . '/*.cache') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->cachePath)) {
            rmdir($this->cachePath);
        }
    }

    public function testFileCacheStoreSupportsBasicOperations(): void
    {
        $store = new FileCacheStore($this->cachePath);

        self::assertTrue($store->put('user.profile', ['name' => 'Myxa']));
        self::assertTrue($store->has('user.profile'));
        self::assertSame(['name' => 'Myxa'], $store->get('user.profile'));
        self::assertSame('fallback', $store->get('missing', 'fallback'));
        self::assertTrue($store->forget('user.profile'));
        self::assertFalse($store->forget('user.profile'));
    }

    public function testFileCacheStoreExpiresPayloads(): void
    {
        $store = new FileCacheStore($this->cachePath);

        self::assertTrue($store->put('temp', 'value', -1));
        self::assertFalse($store->has('temp'));
        self::assertNull($store->get('temp'));
    }

    public function testFileCacheStoreCanClearAllCachedFiles(): void
    {
        $store = new FileCacheStore($this->cachePath);

        $store->put('one', 1);
        $store->put('two', 2);

        self::assertTrue($store->clear());
        self::assertFalse($store->has('one'));
        self::assertFalse($store->has('two'));
    }

    public function testRedisCacheStoreSupportsBasicOperations(): void
    {
        $store = new RedisCacheStore(new RedisConnection(new InMemoryRedisStore()));

        self::assertTrue($store->put('user.profile', ['name' => 'Myxa']));
        self::assertTrue($store->has('user.profile'));
        self::assertSame(['name' => 'Myxa'], $store->get('user.profile'));
        self::assertSame('fallback', $store->get('missing', 'fallback'));
        self::assertTrue($store->forget('user.profile'));
        self::assertFalse($store->forget('user.profile'));
    }

    public function testRedisCacheStoreExpiresPayloadsAndClearsPrefixedKeys(): void
    {
        $backend = new InMemoryRedisStore();
        $backend->set('outside', 'keep');

        $store = new RedisCacheStore(new RedisConnection($backend));

        self::assertTrue($store->put('temp', 'value', -1));
        self::assertFalse($store->has('temp'));
        self::assertTrue($store->put('one', 1));
        self::assertTrue($store->put('two', 2));
        self::assertTrue($store->clear());
        self::assertSame('keep', $backend->get('outside'));
    }

    public function testRedisCacheStoreForgetsMalformedPayloadsAndRejectsUnsupportedClearing(): void
    {
        $backend = new InMemoryRedisStore();
        $backend->set('cache:broken', '{"missing":"value"}');
        $store = new RedisCacheStore(new RedisConnection($backend));

        self::assertSame('fallback', $store->get('broken', 'fallback'));
        self::assertNull($backend->get('cache:broken'));

        $unsupported = new RedisCacheStore(new RedisConnection(new CacheTestUnsupportedRedisStore()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis cache clearing is not supported by this Redis store.');

        $unsupported->clear();
    }

    public function testManagerSupportsStoresFactoriesAndRemember(): void
    {
        $manager = new CacheManager('local', new FileCacheStore($this->cachePath));
        $manager->addStore('redis', fn (): RedisCacheStore => new RedisCacheStore(new RedisConnection(new InMemoryRedisStore())));
        $manager->setDefaultStore('local');

        self::assertSame('local', $manager->getDefaultStore());
        self::assertTrue($manager->hasStore('redis'));
        self::assertTrue($manager->put('count', 5));
        self::assertSame(5, $manager->get('count'));
        self::assertSame(['ready' => true], $manager->remember('expensive', static fn (): array => ['ready' => true]));
        self::assertSame(['ready' => true], $manager->remember('expensive', static fn (): array => ['fresh' => false]));
        self::assertTrue($manager->has('expensive'));
        self::assertTrue($manager->forever('forever', 'kept'));
        self::assertSame('kept', $manager->get('forever'));
        self::assertTrue($manager->forget('count'));
        self::assertTrue($manager->put('remote', 9, store: 'redis'));
        self::assertSame(9, $manager->get('remote', store: 'redis'));
        self::assertTrue($manager->clear('redis'));
    }

    public function testManagerRejectsInvalidAliasesAndMissingStores(): void
    {
        try {
            new CacheManager(' ');
            self::fail('Expected invalid alias exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Cache store alias cannot be empty.', $exception->getMessage());
        }

        $manager = new CacheManager('local', new FileCacheStore($this->cachePath));

        try {
            $manager->store('missing');
            self::fail('Expected missing store exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Cache store alias "missing" is not registered.', $exception->getMessage());
        }

        try {
            $manager->addStore('broken', static fn (CacheManager $one, string $two): FileCacheStore => new FileCacheStore('cache'));
            self::fail('Expected invalid factory signature exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Cache store factory for alias "broken" must accept zero or one parameter.',
                $exception->getMessage(),
            );
        }

        $manager->addStore('broken', static fn (): mixed => new \stdClass(), true);

        $this->expectException(\TypeError::class);

        $manager->store('broken');
    }

    public function testServiceProviderAndFacadeBootstrapCacheManager(): void
    {
        $app = new Application();
        $app->register(new CacheServiceProvider(
            stores: [
                'local' => new FileCacheStore($this->cachePath),
                'redis' => new RedisCacheStore(new RedisConnection(new InMemoryRedisStore())),
            ],
        ));
        $app->boot();

        $manager = $app->make(CacheManager::class);

        self::assertSame($manager, $app->make('cache'));
        self::assertSame($manager, Cache::getManager());
        self::assertTrue(Cache::put('count', 10));
        self::assertSame(10, Cache::get('count'));
        self::assertTrue(Cache::put('remote', ['ok' => true], store: 'redis'));
        self::assertSame(['ok' => true], Cache::get('remote', store: 'redis'));
    }

    public function testFacadeThrowsClearExceptionForUnknownMethod(): void
    {
        Cache::setManager(new CacheManager('local', new FileCacheStore($this->cachePath)));

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cache facade method "foobar" is not supported.');

        Cache::foobar();
    }
}

final class CacheTestUnsupportedRedisStore implements \Myxa\Redis\Connection\RedisStoreInterface
{
    public function get(string $key): string|int|float|bool|null
    {
        return null;
    }

    public function set(string $key, string|int|float|bool|null $value): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function increment(string $key, int $by = 1): int
    {
        return $by;
    }
}
