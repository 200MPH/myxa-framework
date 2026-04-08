# Events

The event system is intentionally small and synchronous by default.

Core parts:

- `EventInterface`
- `AbstractEvent`
- `EventHandlerInterface`
- `EventListenerRegistry`
- `EventBus`
- `EventServiceProvider`
- `Event` facade

## Define an Event

```php
use Myxa\Events\AbstractEvent;

final readonly class UserRegistered extends AbstractEvent
{
    public function __construct(
        public int $userId,
        public string $email,
    ) {
        parent::__construct();
    }
}
```

Each event exposes:

- `eventName(): string`
- `occurredAt(): DateTimeInterface`

## Define a Handler

```php
use Myxa\Events\EventHandlerInterface;
use Myxa\Events\EventInterface;

final class SendWelcomeEmail implements EventHandlerInterface
{
    public function handle(EventInterface $event): void
    {
        if (!$event instanceof UserRegistered) {
            return;
        }

        // send email
    }
}
```

## Register Listeners

```php
use Myxa\Events\EventServiceProvider;

$app->register(new EventServiceProvider([
    UserRegistered::class => [
        SendWelcomeEmail::class,
    ],
]));
```

## Dispatch Events

```php
use Myxa\Support\Facades\Event;

Event::dispatch(new UserRegistered(1, 'john@example.com'));
```

## Notes

- handlers are resolved through the container
- dispatch is synchronous in the current implementation
- the registry maps one event class to one or many handlers
