<?php

declare(strict_types=1);

namespace Test\Unit\Console;

use Myxa\Console\ConsoleInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsoleInput::class)]
final class ConsoleInputTest extends TestCase
{
    public function testConsoleInputExposesCommandParametersOptionsAndFlags(): void
    {
        $input = new ConsoleInput(
            'users:create',
            ['name' => 'Ada'],
            ['force' => true],
            true,
            true,
            false,
        );

        self::assertSame('users:create', $input->command());
        self::assertSame('Ada', $input->parameter('name'));
        self::assertSame('guest', $input->parameter('missing', 'guest'));
        self::assertSame(['name' => 'Ada'], $input->parameters());
        self::assertTrue($input->option('force'));
        self::assertSame('fallback', $input->option('missing', 'fallback'));
        self::assertSame(['force' => true], $input->options());
        self::assertTrue($input->interactive());
        self::assertTrue($input->quiet());
        self::assertFalse($input->help());
    }
}
