# Redis

Redis support is a lightweight key-value layer, separate from SQL and Mongo.

## Setup

You need to create a `RedisManager`, register at least one connection, and then use that manager directly or through the facade.

### Real Redis connection

```php
use Myxa\Redis\Connection\PhpRedisStore;
use Myxa\Redis\Connection\RedisConnection;
use Myxa\Redis\RedisManager;

$manager = new RedisManager('main', new RedisConnection(
    new PhpRedisStore(
        host: 'redis',
        port: 6379,
        database: 0,
    ),
));
```

### In-memory connection

This is useful for tests and local experiments when you do not want a real Redis server.

```php
use Myxa\Redis\RedisManager;
use Myxa\Redis\Connection\InMemoryRedisStore;
use Myxa\Redis\Connection\RedisConnection;

$manager = new RedisManager('main', new RedisConnection(new InMemoryRedisStore()));
```

## Usage

Once the connection is registered, you can read and write values through the manager.

```php
$manager->set('visits', 1);
$manager->increment('visits');
$count = $manager->get('visits'); // 2
```

## Facade

If you prefer the facade, set the manager first and then call Redis operations through it.

```php
use Myxa\Support\Facades\Redis;

Redis::setManager($manager);

Redis::set('visits', 1);
Redis::increment('visits');
$count = Redis::get('visits'); // 2
```

## Notes

- dedicated `RedisConnection` and `RedisManager`
- constructor can register the default connection directly
- facade support via `Myxa\Support\Facades\Redis`
- initial commands: `get`, `set`, `delete`, `has`, `increment`
- `PhpRedisStore` uses the `phpredis` extension
- includes an in-memory store for tests and local development experiments
