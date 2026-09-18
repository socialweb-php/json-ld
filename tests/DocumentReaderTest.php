<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\DocumentReader;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\JsonLd\Exception\MalformedJson;
use SocialWeb\JsonLd\Limits;
use stdClass;

use function json_decode;
use function json_encode;
use function str_repeat;

use const JSON_ERROR_SYNTAX;
use const JSON_ERROR_UTF8;
use const JSON_THROW_ON_ERROR;
use const NAN;
use const PHP_INT_MAX;

class DocumentReaderTest extends TestCase
{
    public function testDecodesAStringWithObjectsAsStdClass(): void
    {
        $document = (new DocumentReader(new Limits()))->read('{"a": {}, "b": [], "c": [1, "x", true, null, 1.5]}');

        $this->assertInstanceOf(stdClass::class, $document);
        $this->assertEquals((object) ['a' => new stdClass(), 'b' => [], 'c' => [1, 'x', true, null, 1.5]], $document);
        $this->assertInstanceOf(stdClass::class, $document->a);
        $this->assertSame([], $document->b);
    }

    public function testAcceptsAScalarDocument(): void
    {
        $reader = new DocumentReader(new Limits());

        $this->assertSame(5, $reader->read('5'));
        $this->assertSame('x', $reader->read('"x"'));
        $this->assertNull($reader->read('null'));
        $this->assertTrue($reader->read('true'));
        $this->assertSame(2.5, $reader->read('2.5'));
    }

    public function testReadsAnStdClassTreeIntoANewTree(): void
    {
        $input = json_decode('{"a": {"b": [{"c": 1}]}, "d": {}}', false, 512, JSON_THROW_ON_ERROR);

        $document = (new DocumentReader(new Limits()))->read($input);

        $this->assertEquals($input, $document);
        $this->assertNotSame($input, $document);
        $this->assertInstanceOf(stdClass::class, $document);
        $this->assertInstanceOf(stdClass::class, $document->a);
        $this->assertNotSame($input->a, $document->a);
    }

    public function testConvertsAssociativeArraysUnderTheArrayRule(): void
    {
        $document = (new DocumentReader(new Limits()))->read([
            'object' => ['x' => 1],
            'list' => [1, 2],
            'empty' => [],
            'sparse' => [1 => 'a'],
        ]);

        $this->assertEquals(
            (object) [
                'object' => (object) ['x' => 1],
                'list' => [1, 2],
                'empty' => [],
                'sparse' => (object) ['1' => 'a'],
            ],
            $document,
        );
    }

    public function testAListAtTheTopLevelIsAJsonArray(): void
    {
        $this->assertSame([1, 2], (new DocumentReader(new Limits()))->read([1, 2]));
        $this->assertSame([], (new DocumentReader(new Limits()))->read([]));
    }

    public function testKeepsNumericStringKeysAsObjectKeys(): void
    {
        $document = (new DocumentReader(new Limits()))->read('{"1": "one", "10": "ten"}');

        $this->assertSame('{"1":"one","10":"ten"}', json_encode($document));
    }

    #[DataProvider('malformedStrings')]
    public function testRejectsAStringThatIsNotJson(string $json, int $expectedError): void
    {
        try {
            (new DocumentReader(new Limits()))->read($json);
            $this->fail('Expected MalformedJson');
        } catch (MalformedJson $exception) {
            $this->assertSame($expectedError, $exception->jsonError);
            $this->assertStringStartsWith('Malformed JSON: ', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function malformedStrings(): iterable
    {
        yield 'empty' => ['', JSON_ERROR_SYNTAX];
        yield 'truncated' => ['{"a": ', JSON_ERROR_SYNTAX];
        yield 'trailing comma' => ['[1,]', JSON_ERROR_SYNTAX];
        yield 'invalid UTF-8' => ["\"\xff\"", JSON_ERROR_UTF8];
    }

    public function testRejectsANonFiniteNumberInAString(): void
    {
        try {
            (new DocumentReader(new Limits()))->read('[1E400]');
            $this->fail('Expected MalformedJson');
        } catch (MalformedJson $exception) {
            $this->assertSame(0, $exception->jsonError);
            $this->assertSame('Malformed JSON: a number is not finite', $exception->getMessage());
        }
    }

    public function testRejectsANonFiniteNumberInADecodedDocument(): void
    {
        $this->expectException(MalformedJson::class);
        $this->expectExceptionMessageIsOrContains('a number is not finite');

        (new DocumentReader(new Limits()))->read(['x' => NAN]);
    }

    public function testRejectsAStringThatIsNotUtf8InADecodedDocument(): void
    {
        try {
            (new DocumentReader(new Limits()))->read(['x' => "\xff"]);
            $this->fail('Expected MalformedJson');
        } catch (MalformedJson $exception) {
            $this->assertSame(0, $exception->jsonError);
            $this->assertSame('Malformed JSON: a string is not valid UTF-8', $exception->getMessage());
        }
    }

    public function testRejectsAnObjectThatIsNotStdClass(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('A document may hold only JSON values; DateTimeImmutable given');

        (new DocumentReader(new Limits()))->read(['x' => new DateTimeImmutable()]);
    }

    public function testRejectsATopLevelObjectThatIsNotStdClass(): void
    {
        $this->expectException(InvalidArgument::class);

        (new DocumentReader(new Limits()))->read(new DateTimeImmutable());
    }

    public function testATopLevelObjectIsDepthOne(): void
    {
        $reader = new DocumentReader(new Limits(maxDepth: 1));

        $this->assertEquals((object) ['a' => 1], $reader->read('{"a": 1}'));
        $this->assertEquals((object) ['a' => 1], $reader->read(['a' => 1]));
    }

    public function testRejectsAStringDeeperThanMaxDepth(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The document exceeds the maxDepth limit of 2');

        (new DocumentReader(new Limits(maxDepth: 2)))->read('{"a": {"b": {}}}');
    }

    public function testDepthErrorFromTheDecoderCarriesTheLimit(): void
    {
        try {
            (new DocumentReader(new Limits(maxDepth: 3)))->read(str_repeat('[', 4) . str_repeat(']', 4));
            $this->fail('Expected LimitExceeded');
        } catch (LimitExceeded $exception) {
            $this->assertSame('maxDepth', $exception->limit);
            $this->assertSame(3, $exception->value);
            $this->assertNotNull($exception->getPrevious());
        }
    }

    public function testAcceptsAStringExactlyAtMaxDepth(): void
    {
        $this->assertSame([[[1]]], (new DocumentReader(new Limits(maxDepth: 3)))->read('[[[1]]]'));
    }

    public function testRejectsADecodedDocumentDeeperThanMaxDepth(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The document exceeds the maxDepth limit of 2');

        (new DocumentReader(new Limits(maxDepth: 2)))->read(['a' => ['b' => ['c' => 1]]]);
    }

    public function testRejectsADecodedObjectTreeDeeperThanMaxDepth(): void
    {
        $input = json_decode('{"a": {"b": {}}}', false, 512, JSON_THROW_ON_ERROR);

        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The document exceeds the maxDepth limit of 2');

        (new DocumentReader(new Limits(maxDepth: 2)))->read($input);
    }

    public function testRejectsADecodedListDeeperThanMaxDepth(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The document exceeds the maxDepth limit of 2');

        (new DocumentReader(new Limits(maxDepth: 2)))->read([[[1]]]);
    }

    public function testAcceptsADecodedDocumentExactlyAtMaxDepth(): void
    {
        $reader = new DocumentReader(new Limits(maxDepth: 2));

        $this->assertSame([[1]], $reader->read([[1]]));
        $this->assertEquals((object) ['a' => (object) []], $reader->read(['a' => (object) []]));
    }

    public function testTheLargestDepthTheDecoderAcceptsStillDecodes(): void
    {
        $this->assertSame([1], (new DocumentReader(new Limits(maxDepth: 2_147_483_647)))->read('[1]'));
        $this->assertSame([1], (new DocumentReader(new Limits(maxDepth: 2_147_483_648)))->read('[1]'));
    }

    public function testCountsEveryValueIncludingScalarsAndContainers(): void
    {
        // The object, the number, the list, and the two items in it: five values.
        $document = '{"a": 1, "b": [true, null]}';

        $this->assertEquals(
            (object) ['a' => 1, 'b' => [true, null]],
            (new DocumentReader(new Limits(maxValues: 5)))->read($document),
        );

        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The document exceeds the maxValues limit of 4');

        (new DocumentReader(new Limits(maxValues: 4)))->read($document);
    }

    public function testCountsValuesOfADecodedDocument(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The document exceeds the maxValues limit of 2');

        (new DocumentReader(new Limits(maxValues: 2)))->read(['a' => 1, 'b' => 2]);
    }

    public function testCountsAreResetBetweenReads(): void
    {
        $reader = new DocumentReader(new Limits(maxValues: 3));

        $this->assertSame([1, 2], $reader->read('[1, 2]'));
        $this->assertSame([1, 2], $reader->read('[1, 2]'));
    }

    public function testAMaximumIntegerLimitDisablesTheCheck(): void
    {
        $reader = new DocumentReader(new Limits(maxDepth: PHP_INT_MAX, maxValues: PHP_INT_MAX));

        $this->assertSame([[[[1]]]], $reader->read('[[[[1]]]]'));
    }
}
