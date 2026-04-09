# Redis

Redis support is a lightweight key-value layer.

Conceptually, this module is infrastructure-oriented:

- use it directly when you want simple Redis-style key/value access
- use [Cache](../Cache/README.md) when you want cache-store semantics on top of Redis
- use [Database](../Database/README.md) for SQL data access

Available facade:

- `Redis`

## What It Provides

The Redis layer includes:

- `RedisManager` for named connections
- `RedisConnection` for one concrete backend
- `RedisStoreInterface` as the backend contract
- `InMemoryRedisStore` for tests and local development
- `PhpRedisStore` for real Redis servers through the `phpredis` extension

## Use RedisManager Directly

### In-Memory Redis

Useful for tests, local scripts, and places where you do not want a real Redis server:

```php
use Myxa\Redis\Connection\InMemoryRedisStore;
use Myxa\Redis\Connection\RedisConnection;
use Myxa\Redis\RedisManager;

$redis = new RedisManager('main', new RedisConnection(new InMemoryRedisStore()));
```

### Real Redis with `phpredis`

```php
use Myxa\Redis\Connection\PhpRedisStore;
use Myxa\Redis\Connection\RedisConnection;
use Myxa\Redis\RedisManager;

$redis = new RedisManager('main', new RedisConnection(
    new PhpRedisStore(
        host: '127.0.0.1',
        port: 6379,
        timeout: 2.0,
        database: 0,
        password: null,
    ),
));
```

## Basic Operations

```php
$redis->set('visits', 1);
$redis->increment('visits');

$count = $redis->get('visits'); // 2
$exists = $redis->has('visits');
$redis->delete('visits');
```

Supported value types are:

- `string`
- `int`
- `float`
- `bool`
- `null`

## Multiple Connections

You can register multiple named Redis connections:

```php
$redis = new RedisManager('main', new RedisConnection(new InMemoryRedisStore()));

$redis->addConnection('sessions', new RedisConnection(new InMemoryRedisStore()));

$redis->set('token', 'abc', connection: 'sessions');
$token = $redis->get('token', 'sessions');
```

Connections can also be registered lazily with a factory closure:

```php
$redis->addConnection('cache', fn (): RedisConnection => new RedisConnection(
    new InMemoryRedisStore(),
));
```

You can inspect or change the default connection:

```php
$redis->getDefaultConnection();
$redis->setDefaultConnection('sessions');
$connection = $redis->connection();
```

## Connection and Store Layers

`RedisManager` resolves `RedisConnection` instances, and each `RedisConnection` wraps one concrete store backend:

```php
$connection = $redis->connection('main');
$store = $connection->store();
```

This is useful when:

- you want low-level access to the underlying backend
- you want to build infrastructure on top of Redis
- you need backend-specific behavior like `PhpRedisStore::flush()`

## Provider and Facade Usage

In application code, register `RedisServiceProvider` to expose the shared manager and initialize the facade:

```php
use Myxa\Application;
use Myxa\Redis\Connection\InMemoryRedisStore;
use Myxa\Redis\Connection\RedisConnection;
use Myxa\Redis\RedisServiceProvider;

$app = new Application();

$app->register(new RedisServiceProvider(
    connections: [
        'main' => new RedisConnection(new InMemoryRedisStore()),
    ],
    defaultConnection: 'main',
));

$app->boot();
```

Then use the facade:

```php
use Myxa\Support\Facades\Redis;

Redis::set('visits', 1);
Redis::increment('visits', 2);
$count = Redis::get('visits'); // 3

$connection = Redis::connection();
```

## Registry Helpers

`RedisConnection` also supports a small static alias registry:

```php
use Myxa\Redis\Connection\InMemoryRedisStore;
use Myxa\Redis\Connection\RedisConnection;

RedisConnection::register('main', new RedisConnection(new InMemoryRedisStore()), true);

$connection = RedisConnection::get('main');
$exists = RedisConnection::has('main');

RedisConnection::unregister('main');
```

This is useful when you want process-wide fallback connection aliases.

## Notes

- this is not a document or relational model layer; it is a small key-value abstraction
- `increment()` requires the key to contain an integer value
- `PhpRedisStore` requires the `phpredis` extension
- `PhpRedisStore` preserves primitive value types by encoding them before storage
- `InMemoryRedisStore` is ideal for tests and lightweight experiments
- for cache-store semantics built on Redis, see [Cache](../Cache/README.md)
