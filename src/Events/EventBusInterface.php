<?php

declare(strict_types=1);

namespace Myxa\Events;

/**
 * Dispatch domain or application events to registered handlers.
 */
interface EventBusInterface
{
    /**
     * Dispatch a single event instance.
     */
    public function dispatch(EventInterface $event): void;
}
