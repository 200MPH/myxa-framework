# Factory

Factories provide a lightweight way to create fake model data for tests, demos, and local tooling.

The framework ships the factory base class and fake data helpers. Your app owns the concrete factories and decides what “valid fake data” looks like for each model.

## Define A Factory

```php
use Myxa\Database\Factory\Factory;

final class UserFactory extends Factory
{
    protected function model(): string
    {
        return User::class;
    }

    protected function definition(): array
    {
        return [
            'email' => $this->faker()->unique()->email(),
            'status' => $this->faker()->choice(['draft', 'active']),
            'display_name' => $this->faker()->sentence(2, 3),
        ];
    }
}
```

## Attach A Factory To A Model

If you want `User::factory()` style access, add `HasFactory` to the model and return the concrete factory:

```php
use Myxa\Database\Factory\Factory;
use Myxa\Database\Model\HasFactory;
use Myxa\Database\Model\Model;

final class User extends Model
{
    use HasFactory;

    protected string $table = 'users';

    protected ?int $id = null;
    protected string $email = '';
    protected string $status = '';
    protected string $display_name = '';

    protected static function newFactory(): Factory
    {
        return UserFactory::new();
    }
}
```

Then both styles work:

```php
$user = UserFactory::new()->make();
$user = User::factory()->make();
```

## Build Modes

```php
$raw = UserFactory::new()->raw();
$user = UserFactory::new()->make();
$persisted = UserFactory::new()->create();

$users = UserFactory::new()
    ->count(3)
    ->create();
```

What each method does:

- `raw()` returns the final attribute array without creating a model
- `make()` returns an unsaved model instance
- `create()` returns a saved model instance
- `count(3)` repeats the same operation multiple times and returns a list

Examples:

```php
$attributes = UserFactory::new()->raw();

$draft = UserFactory::new()->make();
self::assertFalse($draft->exists());

$persisted = UserFactory::new()->create();
self::assertTrue($persisted->exists());
```

## States And Overrides

`state()` changes the factory defaults before the model is built.

```php
$admin = UserFactory::new()
    ->state(['status' => 'admin'])
    ->create();
```

`create([...])`, `make([...])`, and `raw([...])` apply final one-off overrides.

```php
$admin = UserFactory::new()
    ->state(['status' => 'admin'])
    ->create([
        'email' => 'admin@example.com',
    ]);
```

This means the final attribute order is:

1. `definition()`
2. all `state(...)` calls
3. the attributes passed to `raw()`, `make()`, or `create()`

So these are all valid:

```php
UserFactory::new()->create([
    'email' => 'admin@example.com',
    'status' => 'admin',
]);

UserFactory::new()
    ->state(['status' => 'admin'])
    ->create([
        'email' => 'admin@example.com',
    ]);

UserFactory::new()
    ->state([
        'email' => 'admin@example.com',
        'status' => 'admin',
    ])
    ->create();
```

Use `state()` when a value describes a reusable variant of the factory. Use `create([...])` when a value is specific to this one record.

You can also use a callback state when the next values depend on the current payload or faker:

```php
$user = UserFactory::new()
    ->state(function (array $attributes, \Myxa\Database\Factory\FakeData $faker): array {
        return [
            'status' => 'admin',
            'display_name' => strtoupper($faker->word() . ' ' . $faker->word()),
        ];
    })
    ->create();
```

## Fake Data Helpers

Available helpers include:

- `string()`
- `alpha()`
- `digits()`
- `number()`
- `decimal()`
- `boolean()`
- `choice([...])`
- `word()`
- `words()`
- `sentence()`
- `paragraph()`
- `slug()`
- `email()`
- `unique()->...`

Typical usage:

```php
$payload = [
    'email' => $this->faker()->unique()->email(),
    'status' => $this->faker()->choice(['draft', 'active', 'archived']),
    'age' => $this->faker()->number(18, 80),
    'score' => $this->faker()->decimal(10, 99, 2),
    'nickname' => $this->faker()->alpha(10),
    'bio' => $this->faker()->sentence(),
    'slug' => $this->faker()->slug(),
    'is_public' => $this->faker()->boolean(),
];
```

`unique()` wraps the next generator call and tracks values per scope:

```php
$email = $this->faker()->unique()->email();
$slug = $this->faker()->unique('post-slugs')->slug();
```

If you need a custom unique rule, use `value()`:

```php
$code = $this->faker()
    ->unique('invite-codes')
    ->value(fn (): string => strtoupper($this->faker()->alpha(6)));
```

## Notes

- factories are intentionally small and framework-native
- `state()` calls are cumulative and are applied in the order you add them
- later overrides win over earlier values
- faker uniqueness is tracked in memory on that faker instance
- concrete factory classes belong in the consumer app, not in the framework itself
