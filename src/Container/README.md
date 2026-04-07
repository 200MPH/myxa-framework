# Container

The container provides bindings, singletons, stored instances, autowiring, and callable invocation.

## Basic Usage

```php
use Myxa\Container\Container;

$container = new Container();

$container->singleton(Foo::class);
$container->bind(Bar::class, static fn (Container $app) => new Bar($app->make(Foo::class)));

$bar = $container->make(Bar::class);
```

## Store an Existing Instance

```php
$container->instance('config', [
    'app.name' => 'Myxa',
]);
```

## Call Through the Container

```php
$result = $container->call(function (Foo $foo) {
    return $foo->work();
});
```

## Notes

- the container supports PSR-11 through `get()` and `has()`
- `Application` extends the container and adds service-provider lifecycle support
