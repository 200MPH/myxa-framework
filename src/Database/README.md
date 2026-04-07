# Database

The database layer is split into a few focused parts:

- [Connection](./Connection/): PDO-backed connection configuration and registry
- [Query](./Query/README.md): fluent query builder with driver-aware SQL grammars
- [Model](./Model/README.md): active-record style models with declared properties
- [Schema](./Schema/README.md): schema builder, reverse engineering, snapshots, and diffing
- [Migrations](./Migrations/README.md): migration base class and schema-first workflow

## Quick Start

```php
use Myxa\Database\DatabaseManager;

$db = new DatabaseManager('main');

$users = $db->query()
    ->select('id', 'email')
    ->from('users')
    ->where('status', '=', 'active')
    ->orderBy('id')
    ->getBindings();
```

In application code, register `DatabaseServiceProvider` and use the shared manager or `DB` facade.
