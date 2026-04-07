<?php

declare(strict_types=1);

namespace Myxa\Events;

use Myxa\Application;
use Myxa\Support\Facades\Event;
use Myxa\Support\ServiceProvider;

/**
 * Register the event bus and listener registry.
 */
final class EventServiceProvider extends ServiceProvider
{
    /**
     * @param array<class-string, list<EventHandlerInterface|class-string<EventHandlerInterface>>> $listeners
     */
    public function __construct(private readonly array $listeners = [])
    {
    }

    /**
     * Register shared event services and aliases.
     */
    public function register(): void
    {
        $listeners = $this->listeners;

        $this->app()->singleton(
            EventListenerRegistry::class,
            static fn (): EventListenerRegistry => new EventListenerRegistry($listeners),
        );

        $this->app()->singleton(
            EventBusInterface::class,
            static fn (Application $app): EventBusInterface => new EventBus(
                $app,
                $app->make(EventListenerRegistry::class),
            ),
        );

        $this->app()->singleton(
            EventBus::class,
            static fn (Application $app): EventBus => $app->make(EventBusInterface::class),
        );

        $this->app()->singleton(
            'events',
            static fn (Application $app): EventBusInterface => $app->make(EventBusInterface::class),
        );
    }

    /**
     * Point the static Event facade at the application's shared bus.
     */
    public function boot(): void
    {
        Event::setBus($this->app()->make(EventBusInterface::class));
    }
}
