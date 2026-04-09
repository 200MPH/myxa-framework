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

Reverse engineering is the bridge from an existing database back into framework-managed code.

It is useful when:

- you are adopting Myxa in a project that already has tables
- you inherited a legacy database without clean migration history
- you want to create a baseline migration from a live schema
- you want to snapshot a known-good schema and later detect drift
- you want to scaffold models from an existing table definition

In practice, reverse engineering lets you inspect the live database and turn that structure into framework-native artifacts such as migrations, snapshots, diffs, and models.

```php
$table = DB::schema()->reverseEngineer()->table('users');
$source = DB::schema()->reverseEngineer()->migration('users', 'CreateUsersTable');
```

The most common workflow is:

1. inspect a live table
2. generate a migration that recreates it
3. save a snapshot for later comparison
4. diff old and new versions when the live schema changes

## Create a Migration from an Existing Table

If you already have a live table and want to generate a migration class from it:

```php
use Myxa\Support\Facades\DB;

$source = DB::schema()
    ->reverseEngineer()
    ->migration('users', 'CreateUsersTable');

file_put_contents(
    database_path('migrations/2026_04_09_000000_create_users_table.php'),
    $source,
);
```

This inspects the current `users` table and generates a migration source file that recreates it.

This is especially useful when your database already exists and you want to bring it under version control through generated migration files instead of rewriting the schema by hand.

## Reverse Engineering Workflow

### 1. Inspect a Live Table

Use `table()` when you want normalized metadata for one table:

```php
$users = DB::schema()->reverseEngineer()->table('users');
```

This gives you a structured representation of columns, indexes, and foreign keys.

### 2. Generate a Baseline Migration

Use `migration()` when you want a create-migration source file from a live table:

```php
$source = DB::schema()->reverseEngineer()->migration('users', 'CreateUsersTable');
```

This is the best starting point when a project has an existing schema but no reliable migration history.

### 3. Capture a Snapshot

Use `snapshot()` when you want to record the current schema state of the connection:

```php
$snapshot = DB::schema()->reverseEngineer()->snapshot();

file_put_contents('database/schema/main.json', $snapshot->toJson());
```

Snapshots are useful as a baseline for future comparisons.

### 4. Detect Changes and Generate an Alter Migration

Later, after the live schema changes, compare the stored snapshot with the current table:

```php
$reverse = DB::schema()->reverseEngineer();
$stored = $reverse->snapshotFromJson(file_get_contents('database/schema/main.json'));
$live = $reverse->table('users');

$diff = $reverse->diff($stored->table('users'), $live);
$migration = $reverse->alterMigration($stored->table('users'), $live, 'AlterUsersTable');
```

This is useful when:

- a database was changed manually outside the normal migration flow
- you need to understand what changed between two schema states
- you want Myxa to generate an alter migration from the detected differences

### 5. Generate a Model from a Migration

If you already have a migration class, you can generate a model skeleton from its create blueprint:

```php
$source = DB::schema()->modelFromMigration($migration, 'App\\Models\\User');
```

This is useful when your schema is already expressed as migrations and you want to scaffold model classes from that source of truth.

### 6. Generate a Model from a Live Table

You can also turn an existing table into a model skeleton directly:

```php
$source = DB::schema()->modelFromTable('users', 'App\\Models\\User');
```

That makes reverse engineering useful not only for schema recovery, but also for bootstrapping application code around an existing database.

## Save and Compare Snapshots

```php
$reverse = DB::schema()->reverseEngineer();

$snapshot = $reverse->snapshot();
file_put_contents('database/schema/main.json', $snapshot->toJson());

$stored = $reverse->snapshotFromJson(file_get_contents('database/schema/main.json'));
```

Use snapshots when you want to preserve a known schema baseline for later comparison.

## Notes

- SQLite reverse engineering has type-affinity limits, so some original type intent may come back as `integer` or `text`
- alter-migration generation currently supports adds and drops for columns, indexes, and foreign keys
- modified columns, rename detection, and primary-key changes are intentionally conservative
