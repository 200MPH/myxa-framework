# Query Builder

The query builder is a lightweight fluent SQL builder with driver-aware grammars.

Supported grammar targets:

- MySQL
- PostgreSQL
- SQLite
- SQL Server

## Basic Usage

```php
use Myxa\Support\Facades\DB;

$query = DB::query()
    ->select('id', 'email')
    ->from('users')
    ->where('status', '=', 'active')
    ->orderBy('id', 'DESC')
    ->limit(10);

$rows = DB::select($query->toSql(), $query->getBindings());
```

## Joins

```php
$query = DB::query()
    ->select('u.id', 'p.user_id')
    ->from('users as u')
    ->join('profiles as p', static function ($join): void {
        $join->on('u.id', '=', 'p.user_id')
            ->where('p.status', '=', 1);
    });
```

## Driver-Aware SQL

`DatabaseManager::query()` resolves the grammar from the active connection.

```php
$mysqlSql = $manager->query('mysql')->from('users')->toSql();
$pgsqlSql = $manager->query('pgsql')->from('users')->toSql();
```

This changes identifier quoting and pagination syntax automatically where needed.

## Notes

- the fluent API stays the same across drivers
- advanced driver-specific features like `RETURNING`, `ILIKE`, and JSON operators are not yet modeled as first-class builder methods
