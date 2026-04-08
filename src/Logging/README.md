# Logging

The logging layer is intentionally small and interface-driven.

## Register the Provider

```php
use Myxa\Logging\LoggingServiceProvider;

$app->register(new LoggingServiceProvider());
```

## Bind a Real Logger

```php
use Myxa\Logging\FileLogger;
use Myxa\Logging\LoggerInterface;

$app->singleton(LoggerInterface::class, static fn () => new FileLogger(
    __DIR__ . '/../storage/logs/app.log',
));
```

## Log Messages

```php
use Myxa\Logging\LogLevel;

$logger = $app->make(LoggerInterface::class);
$logger->log(LogLevel::Info, 'User registered', ['id' => 1]);
```

## Debug Facade

For quick local debugging, the framework also includes the `Debug` facade:

```php
use Myxa\Support\Facades\Debug;

Debug::write(['step' => 'booted']);
```

## Notes

- `LoggingServiceProvider` binds `LoggerInterface`
- the default fallback is `NullLogger`
- `FileLogger` writes structured context to disk
