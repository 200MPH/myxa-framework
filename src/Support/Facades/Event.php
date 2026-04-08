<?php

declare(strict_types=1);

namespace Myxa\Support\Facades;

use Myxa\Events\EventBusInterface;
use Myxa\Events\EventInterface;
use RuntimeException;

/**
 * Small static facade for dispatching events.
 */
final class Event
{
    private static ?EventBusInterface $bus = null;

    /**
     * Set the shared event bus used by the facade.
     */
    public static function setBus(EventBusInterface $bus): void
    {
        self::$bus = $bus;
    }

    /**
     * Clear the shared event bus.
     */
    public static function clearBus(): void
    {
        self::$bus = null;
    }

    /**
     * Return the configured shared event bus.
     */
    public static function getBus(): EventBusInterface
    {
        return self::$bus ?? throw new RuntimeException('Event facade has not been initialized.');
    }

    /**
     * Dispatch a single event instance.
     */
    public static function dispatch(EventInterface $event): void
    {
        self::getBus()->dispatch($event);
    }
}
