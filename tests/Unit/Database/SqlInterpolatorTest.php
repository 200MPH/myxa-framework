<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use InvalidArgumentException;
use Myxa\Database\SqlInterpolator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(SqlInterpolator::class)]
final class SqlInterpolatorTest extends TestCase
{
    public function testInterpolateHandlesBooleansNullFloatsAndDoubleColons(): void
    {
        $sql = SqlInterpolator::interpolate(
            'SELECT 1::int AS casted, :active AS active, :ratio AS ratio, :missing AS missing',
            [
                'active' => true,
                'ratio' => 1.5,
                'missing' => null,
            ],
        );

        self::assertSame(
            'SELECT 1::int AS casted, 1 AS active, 1.5 AS ratio, NULL AS missing',
            $sql,
        );
    }

    public function testInterpolateSkipsQuotedSegmentsAndEscapedCharacters(): void
    {
        $sql = SqlInterpolator::interpolate(
            "SELECT \"?\" AS label, `:status` AS identifier, 'it\\'s ?' AS text, :status",
            ['status' => 'active'],
        );

        self::assertSame(
            "SELECT \"?\" AS label, `:status` AS identifier, 'it\\'s ?' AS text, 'active'",
            $sql,
        );
    }

    public function testInterpolateRejectsMissingNamedBinding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing named binding for placeholder ":status".');

        SqlInterpolator::interpolate('SELECT :status', []);
    }

    public function testInterpolateRejectsMissingAndExtraPositionalBindings(): void
    {
        try {
            SqlInterpolator::interpolate('SELECT ?', []);
            self::fail('Expected InvalidArgumentException for missing positional binding.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Not enough positional bindings for SQL placeholders.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Too many positional bindings were provided for SQL placeholders.');

        SqlInterpolator::interpolate('SELECT 1', ['extra']);
    }

    public function testInterpolateRejectsEmptyNamedKeysAndInvalidBindingValues(): void
    {
        try {
            SqlInterpolator::interpolate('SELECT 1', [':' => 'value']);
            self::fail('Expected InvalidArgumentException for empty named binding key.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Named binding key cannot be empty.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Binding value must be a scalar or null.');

        SqlInterpolator::interpolate('SELECT :value', ['value' => new stdClass()]);
    }
}
