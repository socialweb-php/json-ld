<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Rdf;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Rdf\LexicalForms;
use SocialWeb\Test\JsonLd\TestCase;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

class LexicalFormsTest extends TestCase
{
    #[DataProvider('doubleDecisions')]
    public function testDecidesWhetherANumberIsADouble(int | float $value, bool $expected): void
    {
        $this->assertSame($expected, LexicalForms::isDouble($value));
    }

    /**
     * @return iterable<string, array{int | float, bool}>
     */
    public static function doubleDecisions(): iterable
    {
        yield 'integer' => [5, false];
        yield 'largest integer' => [PHP_INT_MAX, false];
        yield 'smallest integer' => [PHP_INT_MIN, false];
        yield 'float with no fractional part' => [5.0, false];
        yield 'negative zero' => [-0.0, false];
        yield 'float with a fractional part' => [5.5, true];
        yield 'tiny fraction' => [1.0e-7, true];
        yield 'just below the threshold' => [999999999999999900000.0, false];
        yield 'at the threshold' => [1.0e21, true];
        yield 'negative at the threshold' => [-1.0e21, true];
    }

    #[DataProvider('integers')]
    public function testWritesIntegers(int | float $value, string $expected): void
    {
        $this->assertSame($expected, LexicalForms::integer($value));
    }

    /**
     * @return iterable<string, array{int | float, string}>
     */
    public static function integers(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'positive' => [42, '42'];
        yield 'negative' => [-42, '-42'];
        yield 'largest integer' => [PHP_INT_MAX, '9223372036854775807'];
        yield 'float' => [5.0, '5'];
        yield 'negative float' => [-5.0, '-5'];
        yield 'float zero' => [0.0, '0'];
        yield 'negative zero' => [-0.0, '0'];
        yield 'float beyond the integer range' => [1.0e20, '100000000000000000000'];
        yield 'float just below the threshold' => [999999999999999900000.0, '999999999999999868928'];
    }

    #[DataProvider('doubles')]
    public function testWritesDoubles(int | float $value, string $expected): void
    {
        $this->assertSame($expected, LexicalForms::double($value));
    }

    /**
     * @return iterable<string, array{int | float, string}>
     */
    public static function doubles(): iterable
    {
        yield 'zero' => [0.0, '0.0E0'];
        yield 'negative zero' => [-0.0, '0.0E0'];
        yield 'one and a half' => [1.5, '1.5E0'];
        yield 'five' => [5.0, '5.0E0'];
        yield 'integer given' => [5, '5.0E0'];
        yield 'negative' => [-12.34, '-1.234E1'];
        yield 'small' => [2.5e-7, '2.5E-7'];
        yield 'threshold' => [1.0e21, '1.0E21'];
        yield 'large' => [1.0e300, '1.0E300'];
        yield 'repeating' => [1.0 / 3.0, '3.333333333333333E-1'];
        yield 'fifteen digits after the point' => [123456789012345680000.0, '1.234567890123457E20'];
        yield 'two thirds rounds down' => [2.0 / 3.0, '6.666666666666666E-1'];
    }

    public function testWritesBooleans(): void
    {
        $this->assertSame('true', LexicalForms::boolean(true));
        $this->assertSame('false', LexicalForms::boolean(false));
    }
}
