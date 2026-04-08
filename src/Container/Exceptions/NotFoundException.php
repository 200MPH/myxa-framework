<?php

declare(strict_types=1);

namespace Myxa\Container\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
