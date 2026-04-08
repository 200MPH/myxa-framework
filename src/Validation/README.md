# Validation

The validation layer uses a fluent API instead of Laravel-style rule strings.

Available facade:

- `Validator`

## Basic Usage

```php
use Myxa\Validation\ValidationManager;

$validator = (new ValidationManager())->make([
    'email' => 'john@example.com',
    'user_id' => 1,
]);

$validator->field('email')
    ->required('Please provide an email address.')
    ->string()
    ->email()
    ->max(255);

$validator->field('user_id')
    ->required()
    ->integer()
    ->exists(User::class, message: fn (mixed $value, string $field) => sprintf(
        'The %s [%s] does not exist.',
        $field,
        (string) $value,
    ));

$validated = $validator->validate();
```

## Facade Usage

```php
use Myxa\Support\Facades\Validator;

$validator = Validator::make([
    'name' => 'John',
    'email' => 'john@example.com',
]);

$validator->field('name')->required()->string()->min(2)->max(50);
$validator->field('email')->required()->string()->email();

if ($validator->fails()) {
    $errors = $validator->errors();
}
```

## Supported Fluent Rules

- `required()`
- `nullable()`
- `string()`
- `integer()`
- `numeric()`
- `boolean()`
- `array()`
- `email()`
- `min($value)`
- `max($value)`
- `exists($source, ?string $column = null)`

Each rule also accepts an optional custom message:

- string: `->required('Email is required.')`
- callable: `->max(255, fn (mixed $value, string $field) => 'Too long.')`

## Notes

- `ValidationServiceProvider` registers the shared validation manager and initializes the facade
- `exists()` supports SQL model classes, Mongo model classes, arrays of allowed values, and custom callables
- `validate()` throws `ValidationException` when validation fails
