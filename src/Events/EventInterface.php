<?php

declare(strict_types=1);

namespace Myxa\Events;

use DateTimeInterface;

/**
 * Contract for framework event messages.
 */
interface EventInterface
{
    /**
     * Return the stable event name.
     */
    public function eventName(): string;

    /**
     * Return when the event occurred.
     */
    public function occurredAt(): DateTimeInterface;
}
