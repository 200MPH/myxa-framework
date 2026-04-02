<?php

declare(strict_types=1);

namespace Myxa\Database\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Cast
{
    public function __construct(
        public string $type,
        public ?string $format = null,
    ) {
    }
}
