<?php

declare(strict_types=1);

namespace Myxa\Events;

/**
 * Handle a dispatched event.
 */
interface EventHandlerInterface
{
    /**
     * React to the provided event instance.
     */
    public function handle(EventInterface $event): void;
}
