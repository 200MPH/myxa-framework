<?php

declare(strict_types=1);

namespace Myxa\Events;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Base event with automatic name and timestamp metadata.
 */
abstract readonly class AbstractEvent implements EventInterface
{
    public function __construct(private DateTimeInterface $occurredAt = new DateTimeImmutable())
    {
    }

    /**
     * Return the stable event name.
     */
    public function eventName(): string
    {
        return static::class;
    }

    /**
     * Return when the event occurred.
     */
    public function occurredAt(): DateTimeInterface
    {
        return $this->occurredAt;
    }
}
