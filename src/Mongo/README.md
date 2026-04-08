# Mongo

Mongo support is intentionally separate from the SQL database layer.

Use `MongoModel` when you want the same declared-property, attribute, hook, and dirty-tracking style as SQL models, but backed by document collections instead of tables.

## Example

```php
use Myxa\Mongo\MongoModel;

final class UserDocument extends MongoModel
{
    protected string $collection = 'users';

    protected string|int|null $_id = null;
    protected string $email = '';
    protected string $status = '';
}
```

## Notes

- `MongoModel` uses `$collection` instead of `$table`
- the default primary key is `_id`
- hooks, casts, hidden fields, guarded fields, and dirty tracking work the same way as SQL models
- this initial implementation focuses on `find()`, `create()`, `save()`, and `delete()`
