<?php

declare(strict_types=1);

namespace Test\Unit\Console;

use Myxa\Console\InputOption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InputOption::class)]
final class InputOptionTest extends TestCase
{
    public function testInputOptionExposesItsMetadata(): void
    {
        $option = new InputOption('force', 'Force creation', true, true, 'yes', 'Boolean');

        self::assertSame('force', $option->name());
        self::assertSame('Force creation', $option->description());
        self::assertTrue($option->acceptsValue());
        self::assertTrue($option->required());
        self::assertSame('yes', $option->default());
        self::assertSame('Boolean', $option->hint());
    }
}
