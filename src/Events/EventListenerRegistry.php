<?php

declare(strict_types=1);

namespace Myxa\Events;

use InvalidArgumentException;

/**
 * In-memory mapping between event classes and their handlers.
 */
final class EventListenerRegistry
{
    /**
     * @var array<class-string, list<EventHandlerInterface|class-string<EventHandlerInterface>>>
     */
    private array $listeners = [];

    /**
     * @param array<class-string, list<EventHandlerInterface|class-string<EventHandlerInterface>>> $listeners
     */
    public function __construct(array $listeners = [])
    {
        foreach ($listeners as $eventClass => $handlers) {
            foreach ($handlers as $handler) {
                $this->listen($eventClass, $handler);
            }
        }
    }

    /**
     * Register a handler for an event class.
     *
     * @param class-string $eventClass
     * @param EventHandlerInterface|class-string<EventHandlerInterface> $handler
     */
    public function listen(string $eventClass, EventHandlerInterface|string $handler): self
    {
        $eventClass = trim($eventClass);
        if ($eventClass === '') {
            throw new InvalidArgumentException('Event class name cannot be empty.');
        }

        if (is_string($handler) && trim($handler) === '') {
            throw new InvalidArgumentException('Event handler class name cannot be empty.');
        }

        $this->listeners[$eventClass] ??= [];
        $this->listeners[$eventClass][] = $handler;

        return $this;
    }

    /**
     * Resolve handlers for an event instance or class name.
     *
     * @return list<EventHandlerInterface|class-string<EventHandlerInterface>>
     */
    public function listenersFor(EventInterface|string $event): array
    {
        $eventClass = is_object($event) ? $event::class : trim($event);

        if ($eventClass === '') {
            throw new InvalidArgumentException('Event class name cannot be empty.');
        }

        return $this->listeners[$eventClass] ?? [];
    }

    /**
     * Determine whether any listeners are registered for an event.
     */
    public function hasListenersFor(EventInterface|string $event): bool
    {
        return $this->listenersFor($event) !== [];
    }

    /**
     * Return the complete listener map.
     *
     * @return array<class-string, list<EventHandlerInterface|class-string<EventHandlerInterface>>>
     */
    public function all(): array
    {
        return $this->listeners;
    }
}
