<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Rdf;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Rdf\JsonCanonicalizer;
use SocialWeb\Test\JsonLd\TestCase;
use stdClass;

use function file_get_contents;
use function hex2bin;
use function json_decode;
use function sprintf;
use function unpack;

use const INF;
use const JSON_THROW_ON_ERROR;
use const NAN;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

class JsonCanonicalizerTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures/jcs';

    /**
     * The input and output pairs of the RFC 8785 reference test data
     */
    #[DataProvider('rfc8785Vectors')]
    public function testMatchesTheRfc8785Vectors(string $name): void
    {
        $input = json_decode(
            self::read(sprintf('%s/input/%s.json', self::FIXTURES, $name)),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        $expected = self::read(sprintf('%s/output/%s.json', self::FIXTURES, $name));

        $this->assertSame($expected, JsonCanonicalizer::canonicalize($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rfc8785Vectors(): iterable
    {
        foreach (['arrays', 'french', 'structures', 'unicode', 'values', 'weird'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * The samples of RFC 8785 appendix B, given as the IEEE 754 bit pattern
     * of each double
     */
    #[DataProvider('rfc8785NumberSamples')]
    public function testMatchesTheRfc8785NumberSamples(string $hex, string $expected): void
    {
        $unpacked = unpack('E', (string) hex2bin($hex));
        $this->assertIsArray($unpacked);
        $this->assertIsFloat($unpacked[1]);

        $this->assertSame($expected, JsonCanonicalizer::canonicalize($unpacked[1]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rfc8785NumberSamples(): iterable
    {
        yield 'zero' => ['0000000000000000', '0'];
        yield 'minus zero' => ['8000000000000000', '0'];
        yield 'min positive number' => ['0000000000000001', '5e-324'];
        yield 'min negative number' => ['8000000000000001', '-5e-324'];
        yield 'max positive number' => ['7fefffffffffffff', '1.7976931348623157e+308'];
        yield 'max negative number' => ['ffefffffffffffff', '-1.7976931348623157e+308'];
        yield 'max positive integer' => ['4340000000000000', '9007199254740992'];
        yield 'max negative integer' => ['c340000000000000', '-9007199254740992'];
        yield 'about 2 to the 68' => ['4430000000000000', '295147905179352830000'];
        yield 'below 1e23' => ['44b52d02c7e14af5', '9.999999999999997e+22'];
        yield '1e23' => ['44b52d02c7e14af6', '1e+23'];
        yield 'above 1e23' => ['44b52d02c7e14af7', '1.0000000000000001e+23'];
        yield 'below 1e21' => ['444b1ae4d6e2ef4e', '999999999999999700000'];
        yield 'just below 1e21' => ['444b1ae4d6e2ef4f', '999999999999999900000'];
        yield '1e21' => ['444b1ae4d6e2ef50', '1e+21'];
        yield 'below 1e-6' => ['3eb0c6f7a0b5ed8c', '9.999999999999997e-7'];
        yield '1e-6' => ['3eb0c6f7a0b5ed8d', '0.000001'];
        yield 'a third, low' => ['41b3de4355555553', '333333333.3333332'];
        yield 'a third, lower' => ['41b3de4355555554', '333333333.33333325'];
        yield 'a third' => ['41b3de4355555555', '333333333.3333333'];
        yield 'a third, higher' => ['41b3de4355555556', '333333333.3333334'];
        yield 'a third, high' => ['41b3de4355555557', '333333333.33333343'];
        yield 'negative small' => ['becbf647612f3696', '-0.0000033333333333333333'];
        yield 'round to even' => ['43143ff3c1cb0959', '1424953923781206.2'];
    }

    #[DataProvider('scalars')]
    public function testWritesScalars(mixed $value, string $expected): void
    {
        $this->assertSame($expected, JsonCanonicalizer::canonicalize($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function scalars(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'integer' => [42, '42'];
        yield 'negative integer' => [-42, '-42'];
        yield 'largest integer' => [PHP_INT_MAX, '9223372036854775807'];
        yield 'smallest integer' => [PHP_INT_MIN, '-9223372036854775808'];
        yield 'integral float' => [56.0, '56'];
        yield 'one' => [1.0, '1'];
        yield 'zero float' => [0.0, '0'];
        yield 'negative zero float' => [-0.0, '0'];
        yield 'a half' => [0.5, '0.5'];
        yield 'negative with fraction' => [-12.34, '-12.34'];
        yield 'negative below one' => [-0.25, '-0.25'];
        yield 'negative integral float' => [-3.0, '-3'];
        yield 'negative exponent' => [1.5e-7, '1.5e-7'];
        yield 'negative number with negative exponent' => [-2.5e-8, '-2.5e-8'];
        yield 'single digit positive exponent' => [2.0e22, '2e+22'];
        yield 'float' => [4.5, '4.5'];
        yield 'float with exponent' => [2.0e-3, '0.002'];
        yield 'float at 1e21' => [1.0e21, '1e+21'];
        yield 'float at 1e20' => [1.0e20, '100000000000000000000'];
        yield 'string' => ['abc', '"abc"'];
        yield 'string with escapes' => ["\"\\/\x08\x0c\n\r\t", '"\"\\\\/\b\f\n\r\t"'];
        yield 'control character' => ["\x1f", '"\\u001f"'];
        yield 'delete character is not escaped' => ["\x7f", "\"\x7f\""];
        yield 'non-ASCII is not escaped' => ['€', '"€"'];
        yield 'line separator is not escaped' => ["\xe2\x80\xa8", "\"\xe2\x80\xa8\""];
    }

    public function testSortsKeysByUtf16CodeUnits(): void
    {
        // U+FF5E (one code unit, FF5E) sorts after U+1F602 (the surrogate pair
        // D83D DE02) in UTF-16 code unit order, though before it in code point
        // order.
        $value = (object) ['～' => 1, '😂' => 2, 'a' => 3];

        $this->assertSame('{"a":3,"😂":2,"～":1}', JsonCanonicalizer::canonicalize($value));
    }

    public function testTreatsAnAssociativeArrayAsAnObjectAndAListAsAnArray(): void
    {
        $this->assertSame('{"1":[],"b":{}}', JsonCanonicalizer::canonicalize(['b' => new stdClass(), 1 => []]));
        $this->assertSame('[1,[2]]', JsonCanonicalizer::canonicalize([1, [2]]));
        $this->assertSame('[]', JsonCanonicalizer::canonicalize([]));
        $this->assertSame('{}', JsonCanonicalizer::canonicalize(new stdClass()));
    }

    public function testRejectsANonFiniteNumber(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('not finite');

        JsonCanonicalizer::canonicalize([INF]);
    }

    public function testRejectsNan(): void
    {
        $this->expectException(InvalidArgument::class);

        JsonCanonicalizer::canonicalize(NAN);
    }

    public function testRejectsAStringThatIsNotUtf8(): void
    {
        try {
            JsonCanonicalizer::canonicalize("\xff");
            $this->fail('Expected InvalidArgument');
        } catch (InvalidArgument $exception) {
            $this->assertSame('JSON cannot represent a string that is not valid UTF-8', $exception->getMessage());
            $this->assertSame(0, $exception->getCode());
            $this->assertNotNull($exception->getPrevious());
        }
    }

    public function testRejectsAKeyThatIsNotUtf8(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('A string must be valid UTF-8 to be encoded as UTF-16');

        JsonCanonicalizer::canonicalize(["\xff" => 1, 'a' => 2]);
    }

    public function testRejectsATypeJsonCannotRepresent(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('JSON cannot represent a value of type DateTimeImmutable');

        JsonCanonicalizer::canonicalize(new DateTimeImmutable());
    }

    private static function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read fixture %s', $path));
        }

        return $contents;
    }
}
