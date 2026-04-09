# Model

Models are active-record style classes built around declared properties.

For document-backed models with a similar declared-property style, see [Mongo](../../Mongo/README.md).

## Basic Model

```php
use Myxa\Database\Model\HasTimestamps;
use Myxa\Database\Model\Model;

final class User extends Model
{
    use HasTimestamps;

    protected string $table = 'users';

    protected ?int $id = null;
    protected string $email = '';
    protected string $status = '';
}
```

## Declared Properties Are Required

Model fields must be declared as real properties on the class.

```php
final class User extends Model
{
    protected string $table = 'users';

    protected ?int $id = null;
    protected string $email = '';
    protected string $status = '';
}
```

This affects both mass assignment and direct writes:

- `fill([...])` accepts only declared properties
- `setAttribute()` and `$model->property = ...` accept only declared properties
- unknown attributes throw an exception
- `#[Internal]` properties are excluded from model field handling

## Metadata Properties

These properties control how the model behaves:

- `$table`: required table name
- `$primaryKey`: primary key column, defaults to `id`
- `$connection`: optional connection alias

Example with a custom primary key:

```php
final class ExternalUser extends Model
{
    protected string $table = 'external_users';
    protected string $primaryKey = 'uuid';

    protected ?string $uuid = null;
    protected string $email = '';
}
```

## Basic Actions

```php
$user = User::create([
    'email' => 'john@example.com',
    'status' => 'active',
]);

$found = User::find(1);
$required = User::findOrFail(1);

$users = User::all();

$user->status = 'inactive';
$user->save();

$user->delete();
```

Useful query helpers:

```php
$users = User::query()
    ->where('status', '=', 'active')
    ->orderBy('id', 'DESC')
    ->limit(10)
    ->get();

$first = User::query()->where('status', '=', 'active')->first();
$exists = User::query()->where('status', '=', 'active')->exists();
```

## Guarded, Hidden, and Internal Attributes

```php
use Myxa\Database\Attributes\Guarded;
use Myxa\Database\Attributes\Hidden;
use Myxa\Database\Attributes\Internal;

final class SecureUser extends Model
{
    protected string $table = 'users';

    protected ?int $id = null;
    protected string $email = '';
    protected string $status = '';

    #[Guarded]
    #[Hidden]
    protected ?string $password_hash = null;

    #[Internal]
    protected string $helperLabel = 'draft';
}
```

- `#[Guarded]`: skipped by `fill([...])`, but trusted code can still set it directly
- `#[Hidden]`: omitted from `toArray()` and `toJson()`
- `#[Internal]`: not treated as a persisted model field at all

## Casting

The built-in casts currently support datetime values.

```php
use DateTimeImmutable;
use Myxa\Database\Attributes\Cast;
use Myxa\Database\Model\CastType;

final class User extends Model
{
    protected string $table = 'users';

    protected string $email = '';
    protected string $status = '';

    #[Cast(CastType::DateTimeImmutable, format: DATE_ATOM)]
    protected ?DateTimeImmutable $created_at = null;

    #[Cast(CastType::DateTimeImmutable, format: DATE_ATOM)]
    protected ?DateTimeImmutable $updated_at = null;
}
```

Behavior:

- hydrated string values are cast into `DateTime` or `DateTimeImmutable`
- serialized output converts them back to strings
- the cast format controls both parsing and serialization

## Extra Attributes

There is no public `extra()` API on models.

The model is strict during normal writes:

- `fill([...])` rejects unknown attributes
- `setAttribute()` rejects unknown attributes

But hydrated rows may still contain additional storage columns. Those values are available through `getAttribute()` and are included in serialization unless hidden.

```php
$user = ExternalUser::hydrate([
    'uuid' => 'abc-1',
    'email' => 'external@example.com',
    'computed_label' => 'External',
]);

$user->getAttribute('computed_label'); // 'External'
$user->toArray()['computed_label'];    // 'External'
```

## Relationships

Relationships are declared as methods returning `hasOne()`, `hasMany()`, or `belongsTo()`.

```php
use Myxa\Database\Model\Model;
use Myxa\Database\Model\ModelQuery;

final class User extends Model
{
    protected string $table = 'users';
    protected ?int $id = null;
    protected string $email = '';
    protected string $status = '';

    public function profile(): ModelQuery
    {
        return $this->hasOne(Profile::class);
    }

    public function posts(): ModelQuery
    {
        return $this->hasMany(Post::class);
    }
}

final class Post extends Model
{
    protected string $table = 'posts';
    protected ?int $id = null;
    protected ?int $user_id = null;
    protected string $title = '';

    public function user(): ModelQuery
    {
        return $this->belongsTo(User::class);
    }
}
```

Default keys are inferred from model names, but you can pass explicit key names into `hasOne()`, `hasMany()`, and `belongsTo()`.

### Lazy Loading

Relation methods return relation queries:

```php
$profile = $user->profile()->first();
$posts = $user->posts()->orderBy('id')->get();
$owner = $post->user()->first();
```

### Eager Loading

Use `with()` on the model query to preload relations, including nested paths:

```php
$users = User::query()
    ->with('profile', 'posts.comments')
    ->orderBy('id')
    ->get();
```

Loaded relations can be checked or accessed with:

```php
$user->relationLoaded('profile');
$user->getRelation('profile');
```

Eager-loaded relations are included automatically in `toArray()` and `toJson()`.

## Array and JSON Serialization

`toArray()` returns model attributes plus any loaded relations:

```php
$payload = $user->toArray();
```

Example output:

```php
[
    'id' => 1,
    'email' => 'john@example.com',
    'status' => 'active',
    'created_at' => '2026-04-01T10:00:00+00:00',
    'updated_at' => '2026-04-01T10:05:00+00:00',
]
```

`toJson()` uses the same serializable payload:

```php
$json = $user->toJson();
```

The model also implements `JsonSerializable`, so `json_encode($user)` uses the same output.

## Cloning

Cloning a model creates a new unsaved copy:

```php
$user = User::findOrFail(1);

$copy = clone $user;
$copy->email = 'copy@example.com';
$copy->save();
```

When cloned:

- the model becomes non-persisted
- the primary key is cleared
- loaded relations are cleared

## Hooks

Use `#[Hook(...)]` on model methods to run code around persistence:

```php
use Myxa\Database\Attributes\Hook;
use Myxa\Database\Model\HookEvent;

final class User extends Model
{
    #[Hook(HookEvent::BeforeSave)]
    protected function normalizeEmail(): void
    {
        $this->email = strtolower(trim($this->email));
    }

    #[Hook(HookEvent::AfterSave)]
    protected function rememberAuditEntry(): void
    {
        // custom post-save logic
    }
}
```

Available hook events:

- `HookEvent::BeforeSave`
- `HookEvent::AfterSave`
- `HookEvent::BeforeUpdate`
- `HookEvent::AfterUpdate`
- `HookEvent::BeforeDelete`
- `HookEvent::AfterDelete`

`save()` handles both inserts and updates. For existing models, `save()` runs both the save hooks and the update hooks.

## Change Tracking

Models keep a snapshot of their last known persisted state so hooks and application code can inspect diffs.

Available helpers:

- `$model->getOriginal()`
- `$model->getOriginal('status')`
- `$model->getDirty()`
- `$model->isDirty()`
- `$model->isDirty('status')`
- `$model->getChanges()`
- `$model->wasChanged()`
- `$model->wasChanged('status')`

Example:

```php
#[Hook(HookEvent::AfterUpdate)]
protected function auditStatusChange(): void
{
    if (!$this->wasChanged('status')) {
        return;
    }

    $before = $this->getOriginal('status');
    $after = $this->getChanges()['status'] ?? null;
}
```

During `AfterSave`, `AfterUpdate`, and `AfterDelete` hooks, `getOriginal()` still exposes the pre-write values and `getChanges()` contains the values written or removed by the operation. After the hooks finish, the model syncs its original snapshot to the latest persisted state.

## Notes

- only declared properties are accepted during normal model assignment
- `HasTimestamps` manages `created_at` and `updated_at`
- models can use a shared manager or a model-specific `$connection`
- relation methods must return a `Relation`/`ModelQuery` built from `hasOne()`, `hasMany()`, or `belongsTo()`
