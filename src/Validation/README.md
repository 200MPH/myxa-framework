# Validation

The validation layer uses a fluent API instead of Laravel-style rule strings.

Available facade:

- `Validator`

## What It Provides

The validation layer gives you:

- `ValidationManager` to create validators
- `Validator` to coordinate multiple fields
- `FieldValidator` for fluent rule configuration per field
- `ValidationException` for throw-on-failure workflows

## Use ValidationManager Directly

```php
use Myxa\Validation\ValidationManager;

$validator = (new ValidationManager())->make([
    'email' => 'john@example.com',
    'user_id' => 1,
]);
```

Then configure rules field by field:

```php
$validator->field('email')
    ->required('Please provide an email address.')
    ->string()
    ->email()
    ->max(255);

$validator->field('user_id')
    ->required()
    ->integer();
```

## Common Flow

```php
$validator = (new ValidationManager())->make([
    'name' => 'John',
    'email' => 'john@example.com',
    'notes' => null,
]);

$validator->field('name')->required()->string()->min(2)->max(50);
$validator->field('email')->required()->string()->email()->max(255);
$validator->field('notes')->nullable()->string();

if ($validator->fails()) {
    $errors = $validator->errors();
} else {
    $validated = $validator->validated();
}
```

Or throw on failure:

```php
$validated = $validator->validate();
```

## Supported Fluent Rules

### Presence and Nullability

- `required()`
- `nullable()`

Behavior:

- missing fields fail only when `required()` is configured
- `nullable()` allows an explicit `null` value
- when a field is `null` and `nullable()` is set, the remaining rules for that field are skipped

### Type and Format Rules

- `string()`
- `integer()`
- `numeric()`
- `boolean()`
- `array()`
- `email()`

### Size Rules

- `min($value)`
- `max($value)`

The size meaning depends on the value type:

- strings use string length
- arrays use item count
- numeric values use their numeric value

## Exists Validation

`exists()` can validate against several sources:

- a SQL model class
- a Mongo model class
- an array of allowed values
- a custom callable returning `true` or `false`

### SQL Model Example

```php
$validator->field('user_id')
    ->required()
    ->integer()
    ->exists(User::class);
```

You can also validate against a specific SQL model column:

```php
$validator->field('email')
    ->required()
    ->string()
    ->exists(User::class, 'email');
```

### Mongo Model Example

```php
$validator->field('document_id')
    ->required()
    ->exists(UserDocument::class);
```

For Mongo models, `exists()` only supports the model primary key.

### Allowed Values Example

```php
$validator->field('role')->exists(['admin', 'editor']);
```

### Custom Callback Example

```php
$validator->field('code')->exists(
    static fn (mixed $value): bool => in_array($value, ['A', 'B'], true),
);
```

## Custom Error Messages

Each rule accepts an optional custom message:

- string message
- callable message

String example:

```php
$validator->field('name')->required('Name is mandatory.');
```

Callable example:

```php
$validator->field('email')->email(
    static fn (mixed $value, string $field): string => sprintf(
        '%s "%s" is invalid.',
        $field,
        (string) $value,
    ),
);
```

## Validated Output

`validated()` and `validate()` return only the configured fields that are present in the input data.

```php
$validator = (new ValidationManager())->make([
    'name' => 'John',
    'email' => 'john@example.com',
    'ignored' => 'value',
]);

$validator->field('name')->required()->string();
$validator->field('email')->required()->string()->email();

$validated = $validator->validate();
```

Result:

```php
[
    'name' => 'John',
    'email' => 'john@example.com',
]
```

## Accessing Errors

```php
if ($validator->fails()) {
    $errors = $validator->errors();
}
```

Error format:

```php
[
    'email' => [
        'The email field must be a valid email address.',
    ],
]
```

## Facade and Service Provider

In application code, register `ValidationServiceProvider` to expose the shared manager and initialize the facade:

```php
use Myxa\Application;
use Myxa\Validation\ValidationServiceProvider;

$app = new Application();
$app->register(new ValidationServiceProvider());
$app->boot();
```

Then use the facade:

```php
use Myxa\Support\Facades\Validator;

$validator = Validator::make([
    'name' => 'John',
    'email' => 'john@example.com',
]);

$validator->field('name')->required()->string();
$validator->field('email')->required()->string()->email();
```

## Exception Handling

`validate()` throws `ValidationException` when validation fails:

```php
use Myxa\Validation\Exceptions\ValidationException;

try {
    $validated = $validator->validate();
} catch (ValidationException $exception) {
    $errors = $exception->errors();
}
```

## Notes

- the API is fluent and field-oriented rather than string-rule oriented
- `passes()` and `fails()` evaluate all configured fields and collect grouped errors
- `validate()` throws, while `validated()` returns the validated subset after a successful validation pass
- `exists()` supports SQL models, Mongo models, arrays of allowed values, and custom callables
