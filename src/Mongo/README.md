# Mongo

Mongo support is intentionally separate from the SQL database layer.

Conceptually, this module sits closer to the model layer than to the SQL query builder:

- if you want SQL tables, query builder, and `Model`, see [Database](../Database/README.md)
- if you want document collections with declared-property models, use `MongoModel`

Available facade:

- `Mongo`

## What It Provides

The Mongo layer includes:

- `MongoManager` for named connections
- `MongoConnection` for registered collections
- `MongoCollectionInterface` as the low-level collection contract
- `InMemoryMongoCollection` for tests and local experiments
- `MongoModel` for document-backed models with declared properties, hooks, casting, and dirty tracking

## Use MongoManager Directly

```php
use Myxa\Mongo\Connection\InMemoryMongoCollection;
use Myxa\Mongo\Connection\MongoConnection;
use Myxa\Mongo\MongoManager;

$users = new InMemoryMongoCollection();
$users->insertOne([
    '_id' => 1,
    'email' => 'john@example.com',
    'status' => 'active',
]);

$mongo = new MongoManager('main');
$mongo->addConnection('main', new MongoConnection([
    'users' => $users,
]));

$document = $mongo->collection('users')->findOne(['_id' => 1]);
```

## Connection and Collection Model

`MongoManager` resolves named connections, and each `MongoConnection` resolves named collections:

```php
$connection = $mongo->connection('main');
$collection = $mongo->collection('users', 'main');
```

You can register connections eagerly or lazily:

```php
$mongo->addConnection('main', new MongoConnection([
    'users' => new InMemoryMongoCollection(),
]));

$mongo->addConnection('archive', fn (): MongoConnection => new MongoConnection([
    'users' => new InMemoryMongoCollection(),
]));
```

## Collection Operations

The low-level collection contract is intentionally small:

```php
$collection->findOne(['_id' => 1]);
$collection->insertOne(['email' => 'jane@example.com']);
$collection->updateOne(['_id' => 1], ['_id' => 1, 'email' => 'updated@example.com']);
$collection->deleteOne(['_id' => 1]);
```

This is useful when:

- you want direct document access without a model class
- you are building adapters around a custom collection implementation
- you are testing Mongo-backed logic with `InMemoryMongoCollection`

## MongoModel

`MongoModel` gives you a model-style API for document collections.

```php
use Myxa\Mongo\MongoModel;

final class UserDocument extends MongoModel
{
    protected string $collection = 'users';

    // Mongo uses _id by default.
    protected string|int|null $_id = null;

    protected string $email = '';
    protected string $status = '';
}
```

When you use `MongoModel` outside `MongoServiceProvider`, point it at a shared manager first:

```php
UserDocument::setManager($mongo);
```

### Basic Actions

```php
UserDocument::setManager($mongo);

$user = UserDocument::create([
    'email' => 'john@example.com',
    'status' => 'active',
]);

$found = UserDocument::find($user->getKey());

$found->status = 'inactive';
$found->save();

$found->delete();
```

### Declared Properties Still Matter

Like SQL `Model`, `MongoModel` is strict about declared fields:

- `fill([...])` accepts only declared properties
- `setAttribute()` and `$model->property = ...` accept only declared properties
- unknown attributes throw an exception
- `#[Internal]` properties stay outside document persistence

### Metadata Properties

These properties control how the model behaves:

- `$collection`: required collection name
- `$primaryKey`: defaults to `_id`
- `$connection`: optional connection alias

Example:

```php
final class ConnectedUserDocument extends MongoModel
{
    protected string $collection = 'users';
    protected ?string $connection = 'mongo-main';

    protected string|int|null $_id = null;
    protected string $email = '';
}
```

### Casting, Hidden, Guarded, Hooks

`MongoModel` reuses the same attribute metadata system as SQL models:

- `#[Cast(...)]`
- `#[Hidden]`
- `#[Guarded]`
- `#[Internal]`
- `#[Hook(...)]`

Example:

```php
use DateTimeImmutable;
use Myxa\Database\Attributes\Cast;
use Myxa\Database\Attributes\Guarded;
use Myxa\Database\Attributes\Hidden;
use Myxa\Database\Model\CastType;

final class SecureUserDocument extends MongoModel
{
    protected string $collection = 'users';
    protected string|int|null $_id = null;
    protected string $email = '';

    #[Guarded]
    #[Hidden]
    protected ?string $secret = null;

    #[Cast(CastType::DateTimeImmutable, format: DATE_ATOM)]
    protected ?DateTimeImmutable $created_at = null;
}
```

### Dirty Tracking and Serialization

`MongoModel` supports the same style of helpers as SQL models:

- `getOriginal()`
- `getDirty()`
- `isDirty()`
- `getChanges()`
- `wasChanged()`
- `toArray()`
- `toJson()`

Example:

```php
$user = UserDocument::find(1);
$user->status = 'archived';

$dirty = $user->getDirty();
$user->save();
$changes = $user->getChanges();
$json = $user->toJson();
```

### Read-Only and Clone Behavior

```php
$user->setReadOnly();
$user->save();   // false
$user->delete(); // false

$copy = clone $user;
```

When cloned:

- the document becomes non-persisted
- the primary key is cleared
- the clone can be saved as a new document

## Use the Facade Through the Service Provider

In application code, register `MongoServiceProvider` to expose the shared manager and initialize the facade and `MongoModel` manager:

```php
use Myxa\Application;
use Myxa\Mongo\Connection\InMemoryMongoCollection;
use Myxa\Mongo\Connection\MongoConnection;
use Myxa\Mongo\MongoServiceProvider;

$app = new Application();

$app->register(new MongoServiceProvider(
    connections: [
        'main' => new MongoConnection([
            'users' => new InMemoryMongoCollection(),
        ]),
    ],
    defaultConnection: 'main',
));

$app->boot();
```

Then use the facade:

```php
use Myxa\Support\Facades\Mongo;

$connection = Mongo::connection();
$collection = Mongo::collection('users');
$document = Mongo::collection('users')->findOne(['_id' => 1]);
```

## Notes

- `MongoModel` is document-backed and does not provide SQL-style relations or a SQL query builder
- `find()` is the main lookup helper on the current implementation
- the default primary key is `_id`
- `InMemoryMongoCollection` is a practical default for tests and local experiments
- for SQL-backed models, see [Database Model](../Database/Model/README.md)
