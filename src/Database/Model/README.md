# Model

Models are active-record style classes built around declared properties.

## Example

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
