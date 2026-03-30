<?php

declare(strict_types=1);

namespace Test\Unit\Console;

use Myxa\Console\InputArgument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InputArgument::class)]
final class InputArgumentTest extends TestCase
{
    public function testInputArgumentExposesItsMetadata(): void
    {
        $argument = new InputArgument('user', 'User name', false, 'guest', 'Name');

        self::assertSame('user', $argument->name());
        self::assertSame('User name', $argument->description());
        self::assertFalse($argument->required());
        self::assertSame('guest', $argument->default());
        self::assertSame('Name', $argument->hint());
    }
}
