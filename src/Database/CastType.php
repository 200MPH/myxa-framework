<?php

declare(strict_types=1);

namespace Myxa\Database;

enum CastType: string
{
    case DateTime = 'datetime';
    case DateTimeImmutable = 'datetime_immutable';
}
