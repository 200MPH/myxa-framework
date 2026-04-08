# Model

Models are active-record style classes built around declared properties.

## Example

```php
use Myxa\Database\Attributes\Hook;
use Myxa\Database\Model\HasTimestamps;
use Myxa\Database\Model\HookEvent;
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

Hook methods can live directly on the model, but traits are often the nicest way to keep models small while still attaching the callbacks to the model lifecycle:

```php
use App\Models\Concerns\UserHooks;

final class User extends Model
{
    use UserHooks;
}
```

```php
namespace App\Models\Concerns;

use Myxa\Database\Attributes\Hook;
use Myxa\Database\Model\HookEvent;

trait UserHooks
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

This works because trait methods become part of the model class, so they are discovered the same way as methods declared directly on the model.

Available hook events:

- `HookEvent::BeforeSave`
- `HookEvent::AfterSave`
- `HookEvent::BeforeUpdate`
- `HookEvent::AfterUpdate`
- `HookEvent::BeforeDelete`
- `HookEvent::AfterDelete`

`save()` handles both inserts and updates. For existing models, `save()` will run the save hooks and the update hooks.

## Change Tracking

Models keep a lightweight snapshot of their last known persisted state so hooks and application code can inspect diffs.

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

## Querying

```php
$user = User::find(1);

$users = User::query()
    ->where('status', '=', 'active')
    ->orderBy('id', 'DESC')
    ->limit(10)
    ->get();
```

## Persisting

```php
$user = User::create([
    'email' => 'john@example.com',
    'status' => 'active',
]);

$user->status = 'inactive';
$user->save();
```

## Notes

- only declared properties are accepted as model attributes
- `$table`, `$primaryKey`, and `$connection` are standard metadata properties
- `HasTimestamps` manages `created_at` and `updated_at`
- date casting is available through the `#[Cast(...)]` attribute
