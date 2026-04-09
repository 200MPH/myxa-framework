# Connection

The connection layer provides PDO-backed connection configuration and an alias-based registry.

## Configuration

Use `PdoConnectionConfig` when you want to describe a connection from structured values:

```php
use Myxa\Database\Connection\PdoConnectionConfig;

$config = new PdoConnectionConfig(
    engine: 'mysql',
    database: 'myxa',
    host: '127.0.0.1',
    port: 3306,
    charset: 'utf8mb4',
    username: 'root',
    password: 'secret',
);
```

If you already have a DSN string, use `fromDsn()`:

```php
use Myxa\Database\Connection\PdoConnectionConfig;

$config = PdoConnectionConfig::fromDsn(
    'pgsql:dbname=myxa;host=127.0.0.1;port=5432',
    'postgres',
    'secret',
);
```

## Register a Connection Alias

Register a connection directly in the global `PdoConnection` registry:

```php
use Myxa\Database\Connection\PdoConnection;

PdoConnection::registerNew('main', $config);
```

You can also register from a DSN in one step:

```php
use Myxa\Database\Connection\PdoConnection;

PdoConnection::registerFromDsn(
    'main',
    'sqlite:dbname=:memory:;host=localhost',
);
```

## Use with DatabaseManager

`DatabaseManager` resolves connections by alias:

```php
use Myxa\Database\DatabaseManager;

$db = new DatabaseManager('main');

$rows = $db->select('SELECT 1 AS value');
```

You can also register connections directly on the manager:

```php
use Myxa\Database\DatabaseManager;

$db = new DatabaseManager();
$db->addConnection('main', $config);
$db->setDefaultConnection('main');
```

## Access the PDO Connection

```php
$connection = $db->connection('main');
$pdo = $db->pdo('main');
```

`PdoConnection` also exposes transaction helpers:

```php
$connection->beginTransaction();
$connection->commit();
$connection->rollBack();
```

## Notes

- `PdoConnection::register()` stores an existing connection instance under an alias
- `PdoConnection::unregister()` removes an alias and disconnects it by default
- `PdoConnection::get()` throws when the alias is missing
- `connect()` is lazy, so the PDO instance is created only when first used
