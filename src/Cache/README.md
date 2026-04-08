# Cache

The cache layer supports named cache stores with a shared manager.

Available facade:

- `Cache`

## Local File Cache

```php
use Myxa\Cache\CacheManager;
use Myxa\Cache\Store\FileCacheStore;

$cache = new CacheManager('local', new FileCacheStore('data/cache'));

$cache->put('users.count', 15);
$count = $cache->get('users.count'); // 15
```

## Redis Cache

```php
use Myxa\Cache\CacheManager;
use Myxa\Cache\Store\RedisCacheStore;
use Myxa\Redis\Connection\PhpRedisStore;
use Myxa\Redis\Connection\RedisConnection;

$cache = new CacheManager('redis', new RedisCacheStore(
    new RedisConnection(new PhpRedisStore(host: 'redis', port: 6379)),
));
```

## Usage

```php
$cache->put('settings', ['theme' => 'light'], 60);
$settings = $cache->get('settings', []);
$remembered = $cache->remember('expensive', fn () => ['ready' => true], 300);
$cache->forget('settings');
```

## Facade Usage

```php
use Myxa\Cache\CacheManager;
use Myxa\Cache\Store\FileCacheStore;
use Myxa\Support\Facades\Cache;

$manager = new CacheManager('local', new FileCacheStore('data/cache'));

Cache::setManager($manager);

Cache::put('users.count', 15);
$count = Cache::get('users.count'); // 15

Cache::put('settings', ['theme' => 'light'], 60);
$settings = Cache::get('settings', []);

$remembered = Cache::remember('expensive', fn () => ['ready' => true], 300);

Cache::put('token', 'abc', store: 'redis');
$token = Cache::get('token', store: 'redis');
```

## Notes

- `CacheServiceProvider` registers the shared cache manager and initializes the facade
- `FileCacheStore` writes cache files under `data/cache` by default
- `RedisCacheStore` reuses the Redis connection layer
