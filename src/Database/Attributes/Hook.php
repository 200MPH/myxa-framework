<?php

declare(strict_types=1);

namespace Myxa\Database\Attributes;

use Attribute;
use Myxa\Database\Model\HookEvent;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Hook
{
    public function __construct(
        public readonly HookEvent $event,
    ) {
    }
}
