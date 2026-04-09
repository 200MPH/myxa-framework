# Cache

The cache layer supports named cache stores behind a shared manager.

Available facade:

- `Cache`

## What It Provides

The cache manager gives you:

- a default cache store plus named stores
- file-backed caching through `FileCacheStore`
- Redis-backed caching through `RedisCacheStore`
- direct manager usage or application/facade usage
- convenience helpers like `remember()` and `forever()`

## Use CacheManager Directly

If you do not want to register `CacheServiceProvider`, you can work with `CacheManager` directly:

```php
use Myxa\Cache\CacheManager;
use Myxa\Cache\Store\FileCacheStore;

$cache = new CacheManager('local', new FileCacheStore('data/cache'));

$cache->put('users.count', 15);
$count = $cache->get('users.count'); // 15
```

## Basic Operations

```php
$cache->put('settings', ['theme' => 'light'], 60);
$settings = $cache->get('settings', []);

$cache->forever('app.name', 'Myxa');
$appName = $cache->get('app.name');

$remembered = $cache->remember('expensive', fn () => ['ready' => true], 300);

$exists = $cache->has('settings');
$cache->forget('settings');
$cache->clear();
```

TTL values are expressed in seconds:

- `null` means store until manually removed
- a positive integer means expire after that many seconds
- expired values are treated as missing

## Named Stores

You can register multiple stores and target them by alias:

```php
use Myxa\Cache\CacheManager;
use Myxa\Cache\Store\FileCacheStore;
use Myxa\Cache\Store\RedisCacheStore;
use Myxa\Redis\Connection\InMemoryRedisStore;
use Myxa\Redis\Connection\RedisConnection;

$cache = new CacheManager('local', new FileCacheStore('data/cache'));

$cache->addStore('redis', new RedisCacheStore(
    new RedisConnection(new InMemoryRedisStore()),
));

$cache->put('token', 'abc', store: 'redis');
$token = $cache->get('token', store: 'redis');
```

`addStore()` also accepts a factory closure when you want lazy store creation:

```php
$cache->addStore('redis', fn (): RedisCacheStore => new RedisCacheStore(
    new RedisConnection(new InMemoryRedisStore()),
));
```

## File Cache Store

`FileCacheStore` writes serialized cache payloads into a directory on disk:

```php
use Myxa\Cache\Store\FileCacheStore;

$store = new FileCacheStore('data/cache');
```

Useful when:

- you want a simple local cache with no extra infrastructure
- you are running tests or development scripts
- you want the default store provided by `CacheServiceProvider`

## Redis Cache Store

`RedisCacheStore` adapts the Redis connection layer into a cache store:

```php
use Myxa\Cache\Store\RedisCacheStore;
use Myxa\Redis\Connection\PhpRedisStore;
use Myxa\Redis\Connection\RedisConnection;

$store = new RedisCacheStore(
    new RedisConnection(new PhpRedisStore(
        host: 'redis',
        port: 6379,
        database: 0,
    )),
    prefix: 'cache:',
);
```

Useful when:

- cached values should be shared across processes or hosts
- you already use Redis in the application
- you want cache and Redis to share the same infrastructure

## Use the Facade Through the Service Provider

In application code, register `CacheServiceProvider` to expose the shared manager and initialize the facade:

```php
use Myxa\Application;
use Myxa\Cache\CacheServiceProvider;
use Myxa\Cache\Store\FileCacheStore;
use Myxa\Cache\Store\RedisCacheStore;
use Myxa\Redis\Connection\InMemoryRedisStore;
use Myxa\Redis\Connection\RedisConnection;

$app = new Application();

$app->register(new CacheServiceProvider(
    stores: [
        'local' => new FileCacheStore('data/cache'),
        'redis' => new RedisCacheStore(new RedisConnection(new InMemoryRedisStore())),
    ],
    defaultStore: 'local',
));

$app->boot();
```

Then use the facade:

```php
use Myxa\Support\Facades\Cache;

Cache::put('users.count', 15);
$count = Cache::get('users.count');

Cache::put('token', 'abc', store: 'redis');
$token = Cache::get('token', store: 'redis');
```

## Store Selection

The manager resolves stores in this order:

1. the explicitly requested store alias
2. otherwise the configured default store

You can inspect or change the default store:

```php
$cache->getDefaultStore();
$cache->setDefaultStore('redis');
$store = $cache->store();
```

## Notes

- `CacheManager` works without `CacheServiceProvider`
- `CacheServiceProvider` registers the shared cache manager and initializes the facade
- when no explicit default store is provided through the provider, `FileCacheStore('data/cache')` is used for the default alias
- `remember()` only resolves the callback when the key is missing
- `RedisCacheStore` builds on the Redis connection layer documented in [Redis](../Redis/README.md)
