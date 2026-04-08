<?php

declare(strict_types=1);

namespace Myxa\Events;

use InvalidArgumentException;
use Myxa\Container\ContainerInterface;

/**
 * Default synchronous event bus backed by the application container.
 */
final class EventBus implements EventBusInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly EventListenerRegistry $listeners,
    ) {
    }

    /**
     * Dispatch a single event instance to all registered handlers.
     */
    public function dispatch(EventInterface $event): void
    {
        foreach ($this->listeners->listenersFor($event) as $listener) {
            $handler = is_string($listener)
                ? $this->container->make($listener)
                : $listener;

            if (!$handler instanceof EventHandlerInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Event handler [%s] must implement %s.',
                    is_object($handler) ? $handler::class : get_debug_type($handler),
                    EventHandlerInterface::class,
                ));
            }

            $handler->handle($event);
        }
    }
}
