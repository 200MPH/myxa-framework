# Schema

The schema layer provides:

- fluent table creation and alteration
- driver-specific grammars for MySQL, PostgreSQL, SQLite, and SQL Server
- reverse engineering from live database tables
- JSON schema snapshots
- table diffing and alter-migration generation
- model generation from tables or migrations

## Create Tables

```php
use Myxa\Support\Facades\DB;
use Myxa\Database\Schema\Blueprint;

DB::schema()->create('users', function (Blueprint $table): void {
    $table->id();
    $table->string('email')->unique();
    $table->string('name');
    $table->timestamps();
});
```

## Alter Tables

```php
DB::schema()->table('users', function (Blueprint $table): void {
    $table->string('nickname', 120)->nullable();
    $table->renameColumn('name', 'full_name');
});
```

## Run Raw Statements

```php
DB::schema()->statement(
    'UPDATE users SET nickname = ? WHERE nickname IS NULL',
    ['anonymous'],
);
```

## Reverse Engineer a Table

```php
$table = DB::schema()->reverseEngineer()->table('users');
$source = DB::schema()->reverseEngineer()->migration('users', 'CreateUsersTable');
```

## Save and Compare Snapshots

```php
$reverse = DB::schema()->reverseEngineer();

$snapshot = $reverse->snapshot();
file_put_contents('database/schema/main.json', $snapshot->toJson());

$stored = $reverse->snapshotFromJson(file_get_contents('database/schema/main.json'));
$live = $reverse->table('users');

$diff = $reverse->diff($stored->table('users'), $live);
$migration = $reverse->alterMigration($stored->table('users'), $live, 'AlterUsersTable');
```

## Generate Models

```php
$fromTable = DB::schema()->modelFromTable('posts', 'App\\Models\\Post');
$fromMigration = DB::schema()->modelFromMigration($migration, 'App\\Models\\Post');
```

## Notes

- SQLite reverse engineering has type-affinity limits, so some original type intent may come back as `integer` or `text`
- alter-migration generation currently supports adds and drops for columns, indexes, and foreign keys
- modified columns, rename detection, and primary-key changes are intentionally conservative
