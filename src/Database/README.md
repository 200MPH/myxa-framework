# Database

The database layer is split into a few focused parts:

- [Connection](./Connection/README.md): PDO-backed connection configuration and registry
- [Query Builder](./Query/README.md): fluent query builder with driver-aware SQL grammars
- [Model](./Model/README.md): active-record style models with declared properties
- [Factory](./Factory/README.md): lightweight model factories and fake data helpers
- [Schema](./Schema/README.md): schema builder, reverse engineering, snapshots, and diffing
- [Migrations](./Migrations/README.md): migration base class and schema-first workflow

Related, but separate:

- [Mongo](../Mongo/README.md): document collections and Mongo-backed models
- [Redis](../Redis/README.md): lightweight key-value infrastructure used directly or through cache

The examples below show the basic `DatabaseManager` usage with direct SQL strings. If you prefer fluent query construction, you can build SQL and bindings with [`query()`](./Query/README.md) and then execute them through the same manager methods.

## Quick Start

```php
use Myxa\Database\DatabaseManager;

$db = new DatabaseManager('main');

$rows = $db->select(
    'SELECT id, email FROM users WHERE status = ? ORDER BY id',
    ['active'],
);
```

## Insert

```php
$userId = $db->insert(
    'INSERT INTO users (email, status) VALUES (?, ?)',
    ['john@example.com', 'active'],
);
```

## Update

```php
$updatedRows = $db->update(
    'UPDATE users SET status = ? WHERE id = ?',
    ['inactive', 1],
);
```

## Delete

```php
$deletedRows = $db->delete(
    'DELETE FROM users WHERE id = ?',
    [1],
);
```

## Statement

Use `statement()` for SQL that should be executed but does not need fetched rows or an affected-row count:

```php
$executed = $db->statement(
    'CREATE INDEX idx_users_status ON users (status)',
);
```

## DB Facade

In application code, after registering `DatabaseServiceProvider`, you can use the `DB` facade for the same operations:

```php
use Myxa\Support\Facades\DB;

$rows = DB::select(
    'SELECT id, email FROM users WHERE status = ? ORDER BY id',
    ['active'],
);

$userId = DB::insert(
    'INSERT INTO users (email, status) VALUES (?, ?)',
    ['john@example.com', 'active'],
);

$updatedRows = DB::update(
    'UPDATE users SET status = ? WHERE id = ?',
    ['inactive', $userId],
);

$deletedRows = DB::delete(
    'DELETE FROM users WHERE id = ?',
    [$userId],
);
```

## Transactions

Use `transaction()` when you want automatic commit and rollback handling:

```php
$db->transaction(function () use ($db): void {
    $userId = $db->insert(
        'INSERT INTO users (email, status) VALUES (?, ?)',
        ['john@example.com', 'active'],
    );

    $db->insert(
        'INSERT INTO profiles (user_id, display_name) VALUES (?, ?)',
        [$userId, 'John'],
    );
});
```

If an exception is thrown inside the callback, the transaction is rolled back and the exception is rethrown.

You can also manage transactions manually:

```php
$db->beginTransaction();

try {
    $db->update(
        'UPDATE users SET status = ? WHERE id = ?',
        ['inactive', 1],
    );

    $db->commit();
} catch (\Throwable $exception) {
    $db->rollBack();

    throw $exception;
}
```

For fluent SQL construction, use [`query()`](./Query/README.md) to build SQL and bindings before passing them into `select()`, `insert()`, `update()`, or `delete()`.
