<?php

declare(strict_types=1);

namespace Test\Unit\Events;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Myxa\Application;
use Myxa\Events\AbstractEvent;
use Myxa\Events\EventBus;
use Myxa\Events\EventBusInterface;
use Myxa\Events\EventHandlerInterface;
use Myxa\Events\EventInterface;
use Myxa\Events\EventListenerRegistry;
use Myxa\Events\EventServiceProvider;
use Myxa\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final readonly class UserRegistered extends AbstractEvent
{
    public function __construct(
        public int $userId,
        public string $email,
        ?DateTimeInterface $occurredAt = null,
    ) {
        parent::__construct($occurredAt ?? new DateTimeImmutable('2026-04-08T12:00:00+00:00'));
    }
}

final readonly class ProfileCreated extends AbstractEvent
{
    public function __construct(
        public int $userId,
        ?DateTimeInterface $occurredAt = null,
    ) {
        parent::__construct($occurredAt ?? new DateTimeImmutable('2026-04-08T12:05:00+00:00'));
    }
}

final class RecordWelcomeEmail implements EventHandlerInterface
{
    /** @var list<string> */
    public static array $handled = [];

    public function handle(EventInterface $event): void
    {
        if ($event instanceof UserRegistered) {
            self::$handled[] = $event->email;
        }
    }
}

final class RecordProfileCreation implements EventHandlerInterface
{
    /** @var list<int> */
    public static array $handled = [];

    public function handle(EventInterface $event): void
    {
        if ($event instanceof UserRegistered) {
            self::$handled[] = $event->userId;
        }
    }
}

final class InvalidHandler
{
}

#[CoversClass(EventBus::class)]
#[CoversClass(AbstractEvent::class)]
#[CoversClass(EventListenerRegistry::class)]
#[CoversClass(EventServiceProvider::class)]
#[CoversClass(Event::class)]
final class EventTest extends TestCase
{
    protected function setUp(): void
    {
        RecordWelcomeEmail::$handled = [];
        RecordProfileCreation::$handled = [];
        Event::clearBus();
    }

    protected function tearDown(): void
    {
        Event::clearBus();
    }

    public function testRegistryStoresAndResolvesListeners(): void
    {
        $registry = new EventListenerRegistry();
        $handler = new RecordWelcomeEmail();
        $event = new UserRegistered(1, 'john@example.com');

        $registry
            ->listen(UserRegistered::class, RecordProfileCreation::class)
            ->listen(UserRegistered::class, $handler);

        self::assertTrue($registry->hasListenersFor(UserRegistered::class));
        self::assertTrue($registry->hasListenersFor($event));
        self::assertCount(2, $registry->listenersFor(UserRegistered::class));
        self::assertCount(1, $registry->all());
        self::assertSame([], $registry->listenersFor(ProfileCreated::class));
        self::assertSame(UserRegistered::class, $event->eventName());
        self::assertSame('2026-04-08T12:00:00+00:00', $event->occurredAt()->format(DATE_ATOM));
    }

    public function testRegistryValidationMessagesAreHelpful(): void
    {
        $registry = new EventListenerRegistry();

        try {
            $registry->listen(' ', RecordWelcomeEmail::class);
            self::fail('Expected empty event class exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Event class name cannot be empty.', $exception->getMessage());
        }

        try {
            $registry->listen(UserRegistered::class, ' ');
            self::fail('Expected empty handler class exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Event handler class name cannot be empty.', $exception->getMessage());
        }

        try {
            $registry->listenersFor(' ');
            self::fail('Expected empty event lookup exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Event class name cannot be empty.', $exception->getMessage());
        }
    }

    public function testBusDispatchesEventToAllRegisteredHandlers(): void
    {
        $app = new Application();
        $registry = new EventListenerRegistry([
            UserRegistered::class => [
                RecordWelcomeEmail::class,
                RecordProfileCreation::class,
            ],
        ]);

        $app->instance(EventListenerRegistry::class, $registry);

        $bus = new EventBus($app, $registry);
        $bus->dispatch(new UserRegistered(7, 'anna@example.com'));

        self::assertSame(['anna@example.com'], RecordWelcomeEmail::$handled);
        self::assertSame([7], RecordProfileCreation::$handled);
    }

    public function testBusIgnoresEventsWithoutListeners(): void
    {
        $app = new Application();
        $bus = new EventBus($app, new EventListenerRegistry());

        $bus->dispatch(new ProfileCreated(5));

        self::assertSame([], RecordWelcomeEmail::$handled);
        self::assertSame([], RecordProfileCreation::$handled);
    }

    public function testBusRejectsHandlersThatDoNotImplementInterface(): void
    {
        $app = new Application();
        $registry = new EventListenerRegistry([
            UserRegistered::class => [InvalidHandler::class],
        ]);

        $bus = new EventBus($app, $registry);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Event handler [%s] must implement %s.',
            InvalidHandler::class,
            EventHandlerInterface::class,
        ));

        $bus->dispatch(new UserRegistered(3, 'broken@example.com'));
    }

    public function testServiceProviderRegistersBusRegistryAndFacade(): void
    {
        $app = new Application();
        $app->register(new EventServiceProvider([
            UserRegistered::class => [
                RecordWelcomeEmail::class,
                RecordProfileCreation::class,
            ],
        ]));
        $app->boot();

        self::assertInstanceOf(EventListenerRegistry::class, $app->make(EventListenerRegistry::class));
        self::assertInstanceOf(EventBusInterface::class, $app->make(EventBusInterface::class));
        self::assertInstanceOf(EventBus::class, $app->make(EventBus::class));
        self::assertSame($app->make(EventBusInterface::class), $app->make('events'));
        self::assertSame($app->make(EventBusInterface::class), Event::getBus());

        Event::dispatch(new UserRegistered(9, 'provider@example.com'));

        self::assertSame(['provider@example.com'], RecordWelcomeEmail::$handled);
        self::assertSame([9], RecordProfileCreation::$handled);
    }

    public function testEventFacadeThrowsWhenNoBusWasConfigured(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Event facade has not been initialized.');

        Event::dispatch(new UserRegistered(1, 'missing@example.com'));
    }
}
