<?php

declare(strict_types=1);

namespace Myxa\Database\Attributes;

use Attribute;
use Myxa\Database\CastType;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Cast
{
    public function __construct(
        public CastType $type,
        public ?string $format = null,
    ) {
    }
}
