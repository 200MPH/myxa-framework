# Container

The container provides bindings, shared singletons, stored instances, autowiring, and callable invocation.

## Register Services

In practice, "registering a service" means telling the container how to resolve a class, interface, or named key.

Common patterns:

```php
use Myxa\Container\Container;

$container = new Container();

// Register a concrete class as transient.
$container->bind(FooService::class);

// Register an interface to a concrete implementation.
$container->bind(LoggerInterface::class, FileLogger::class);

// Register a shared service.
$container->singleton(CacheManager::class);

// Register a service with a factory closure.
$container->singleton(DatabaseManager::class, static function (): DatabaseManager {
    return new DatabaseManager('main');
});

// Register an already-built value.
$container->instance('config', [
    'app.name' => 'Myxa',
]);
```

After registration, resolve services with `make()` or `get()`:

```php
$logger = $container->make(LoggerInterface::class);
$cache = $container->make(CacheManager::class);
$config = $container->get('config');
```

## Register Service Providers

In full applications, services are often registered through `Application` service providers instead of calling `bind()` or `singleton()` manually in one file.

```php
use Myxa\Application;
use Myxa\Auth\AuthServiceProvider;
use Myxa\Database\DatabaseServiceProvider;
use Myxa\Routing\RouteServiceProvider;

$app = new Application();

$app->register(RouteServiceProvider::class);
$app->register(AuthServiceProvider::class);
$app->register(new DatabaseServiceProvider(
    connections: [
        'main' => $databaseConfig,
    ],
    defaultConnection: 'main',
));

$app->boot();
```

What happens here:

- `register(...)` attaches the provider to the application and runs its `register()` method
- provider `register()` is where bindings and singletons are added to the container
- `boot()` runs each provider's `boot()` method once registration is complete

After that, you resolve the registered services from the application container:

```php
$router = $app->make('router');
$auth = $app->make('auth');
$db = $app->make(\Myxa\Database\DatabaseManager::class);
```

Minimal example provider:

```php
use Myxa\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app()->singleton(MyService::class);
        $this->app()->singleton('my-service', static fn ($app) => $app->make(MyService::class));
    }

    public function boot(): void
    {
        // optional runtime bootstrapping
    }
}
```

## Basic Usage

```php
use Myxa\Container\Container;

$container = new Container();

$container->singleton(Foo::class);
$container->bind(Bar::class, static fn (Container $app) => new Bar($app->make(Foo::class)));

$bar = $container->make(Bar::class);
```

## Main Methods

### `bind()`

Registers a transient binding. The value is rebuilt every time you resolve it.

```php
$container->bind(LoggerInterface::class, FileLogger::class);

$first = $container->make(LoggerInterface::class);
$second = $container->make(LoggerInterface::class);
```

`$first` and `$second` are different instances.

### `singleton()`

Registers a shared binding. The container builds it once, caches it, and returns the same instance on future resolutions.

```php
$container->singleton(LoggerInterface::class, FileLogger::class);

$first = $container->make(LoggerInterface::class);
$second = $container->make(LoggerInterface::class);
```

`$first` and `$second` are the same instance.

### `instance()`

Stores an already-built value directly in the container.

```php
$config = [
    'app.name' => 'Myxa',
];

$container->instance('config', $config);
```

This is different from `singleton()`:

- `instance()` stores the exact value immediately
- `singleton()` stores a recipe and builds the value lazily on first use

## Difference Between `bind()`, `singleton()`, and `instance()`

Use `bind()` when you want a fresh object every time:

```php
$container->bind(RequestIdGenerator::class);
```

Use `singleton()` when one shared object should be reused:

```php
$container->singleton(CacheManager::class);
```

Use `instance()` when you already have the final value:

```php
$container->instance('config', $configArray);
$container->instance(PDO::class, $pdo);
```

Short version:

- `bind()`: rebuild on every `make()`
- `singleton()`: build once, then reuse
- `instance()`: store a prebuilt value now

## `make()`

Resolves an entry from the container.

```php
$service = $container->make(Service::class);
```

You can also pass named parameter overrides for constructor arguments:

```php
$mailer = $container->make(Mailer::class, [
    'dsn' => 'smtp://localhost',
]);
```

## `get()`

PSR-11 alias for `make()`.

```php
$service = $container->get(Service::class);
```

## `has()`

Checks whether the container can resolve an entry.

```php
if ($container->has(Service::class)) {
    $service = $container->make(Service::class);
}
```

This includes:

- explicitly bound entries
- stored instances
- autowirable concrete classes

## Autowiring

Concrete classes can often be resolved without manual registration.

```php
final class Foo
{
}

final class Bar
{
    public function __construct(private Foo $foo)
    {
    }
}

$bar = $container->make(Bar::class);
```

The container reflects the constructor and resolves class-typed dependencies automatically.

## `call()`

Invokes a callable and auto-resolves missing class-typed arguments.

```php
$result = $container->call(function (Foo $foo) {
    return $foo->work();
});
```

Named parameter overrides also work here:

```php
$result = $container->call(
    function (Foo $foo, string $mode) {
        return $foo->work($mode);
    },
    ['mode' => 'safe'],
);
```

`call()` also supports object methods and class methods:

```php
$container->call([$handler, 'handle']);
$container->call([UserController::class, 'show'], ['id' => 5]);
```

## Resolution Order

When resolving an entry, the container checks:

1. stored instances from `instance()`
2. registered bindings from `bind()` or `singleton()`
3. autowirable concrete classes

## Notes

- the container supports PSR-11 through `get()` and `has()`
- `Application` extends the container and adds service-provider lifecycle support
- `singleton()` and `bind()` both accept a class name, closure, or `null`
- circular constructor dependencies throw a binding resolution exception
