<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use DateTimeImmutable;
use JsonException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\DocumentReader;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\JsonLdErrorCode;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\JsonLd\ExpandedDocument;
use SocialWeb\JsonLd\Settings;
use stdClass;

use function fopen;
use function json_decode;
use function json_encode;
use function str_repeat;

use const INF;
use const JSON_ERROR_INVALID_PROPERTY_NAME;
use const JSON_ERROR_SYNTAX;
use const JSON_ERROR_UTF8;
use const JSON_THROW_ON_ERROR;
use const NAN;
use const PHP_INT_MAX;

final class DocumentReaderTest extends TestCase
{
    public function testDecodesAStringWithObjectsAsStdClass(): void
    {
        $document = (new DocumentReader(new Settings()))->read('{"a": {}, "b": [], "c": [1, "x", true, null, 1.5]}');

        $this->assertInstanceOf(stdClass::class, $document);
        $this->assertEquals((object) ['a' => new stdClass(), 'b' => [], 'c' => [1, 'x', true, null, 1.5]], $document);
        $this->assertInstanceOf(stdClass::class, $document->a);
        $this->assertSame([], $document->b);
    }

    public function testAcceptsAScalarDocument(): void
    {
        $reader = new DocumentReader(new Settings());

        $this->assertSame(5, $reader->read('5'));
        $this->assertSame('x', $reader->read('"x"'));
        $this->assertNull($reader->read('null'));
        $this->assertTrue($reader->read('true'));
        $this->assertSame(2.5, $reader->read('2.5'));
    }

    public function testReadsAnStdClassTreeIntoANewTree(): void
    {
        $input = json_decode('{"a": {"b": [{"c": 1}]}, "d": {}}', false, 512, JSON_THROW_ON_ERROR);

        $document = (new DocumentReader(new Settings()))->read($input);

        $this->assertEquals($input, $document);
        $this->assertNotSame($input, $document);
        $this->assertInstanceOf(stdClass::class, $document);
        $this->assertInstanceOf(stdClass::class, $document->a);
        $this->assertNotSame($input->a, $document->a);
    }

    public function testConvertsAssociativeArraysUnderTheArrayRule(): void
    {
        $document = (new DocumentReader(new Settings()))->read([
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
        $this->assertSame([1, 2], (new DocumentReader(new Settings()))->read([1, 2]));
        $this->assertSame([], (new DocumentReader(new Settings()))->read([]));
    }

    public function testKeepsNumericStringKeysAsObjectKeys(): void
    {
        $document = (new DocumentReader(new Settings()))->read('{"1": "one", "10": "ten"}');

        $this->assertSame('{"1":"one","10":"ten"}', json_encode($document));
    }

    #[DataProvider('malformedStrings')]
    public function testRejectsAStringThatIsNotJson(string $json, string $decoderMessage, int $decoderCode): void
    {
        try {
            (new DocumentReader(new Settings()))->read($json);
            $this->fail('Expected JsonLdError');
        } catch (JsonLdError $exception) {
            $this->assertSame(JsonLdErrorCode::LoadingDocumentFailed, $exception->reason);
            $this->assertSame(
                'Loading document failed: the text is not valid JSON (' . $decoderMessage . ')',
                $exception->getMessage(),
            );
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
            $this->assertSame($decoderCode, $exception->getPrevious()->getCode());
        }
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function malformedStrings(): iterable
    {
        yield 'empty' => ['', 'Syntax error', JSON_ERROR_SYNTAX];
        yield 'truncated' => ['{"a": ', 'Syntax error', JSON_ERROR_SYNTAX];
        yield 'trailing comma' => ['[1,]', 'Syntax error', JSON_ERROR_SYNTAX];
        yield 'invalid UTF-8' => [
            "\"\xff\"",
            'Malformed UTF-8 characters, possibly incorrectly encoded',
            JSON_ERROR_UTF8,
        ];
        yield 'NUL byte in a property name' => [
            '{"\\u0000x": 1}',
            'The decoded property name is invalid',
            JSON_ERROR_INVALID_PROPERTY_NAME,
        ];
    }

    /**
     * @param array<mixed> | string | stdClass $document
     */
    #[DataProvider('valuesJsonTextCannotExpress')]
    public function testRejectsAValueThatJsonTextCannotExpress(
        array | string | stdClass $document,
        string $detail,
    ): void {
        try {
            (new DocumentReader(new Settings()))->read($document);
            $this->fail('Expected JsonLdError');
        } catch (JsonLdError $exception) {
            $this->assertSame(JsonLdErrorCode::LoadingDocumentFailed, $exception->reason);
            $this->assertSame('Loading document failed: ' . $detail, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{array<mixed> | string | stdClass, string}>
     */
    public static function valuesJsonTextCannotExpress(): iterable
    {
        yield 'a number too large for a float, in JSON text' => ['[1E400]', 'a number is not finite'];
        yield 'NAN in an array' => [['x' => NAN], 'a number is not finite'];
        yield 'INF in an object' => [(object) ['x' => INF], 'a number is not finite'];
        yield 'a string that is not UTF-8' => [['x' => "\xff"], 'a string is not valid UTF-8'];
        yield 'a property name starting with a NUL byte, in an array' => [
            ["\0x" => 1],
            'a property name starts with a NUL byte',
        ];
        yield 'a property name starting with a NUL byte, in an object' => [
            (object) ["\0x" => 1],
            'a property name starts with a NUL byte',
        ];
        yield 'a property name that is not UTF-8' => [["\xff" => 1], 'a property name is not valid UTF-8'];
    }

    /**
     * @param array<mixed> | stdClass $document
     */
    #[DataProvider('valuesThatAreNotJsonTypes')]
    public function testRejectsAValueWhosePhpTypeIsNotAJsonType(array | stdClass $document, string $type): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('A document may hold only JSON values; ' . $type . ' given');

        (new DocumentReader(new Settings()))->read($document);
    }

    /**
     * @return iterable<string, array{array<mixed> | stdClass, string}>
     */
    public static function valuesThatAreNotJsonTypes(): iterable
    {
        yield 'an object of another class' => [['x' => new DateTimeImmutable()], 'DateTimeImmutable'];
        yield 'an object of another class, in an object' => [
            (object) ['x' => [new DateTimeImmutable()]],
            'DateTimeImmutable',
        ];
        yield 'a stdClass subclass at the top' => [new class extends stdClass {
        }, 'stdClass@anonymous',
        ];
        yield 'a stdClass subclass inside' => [[new class extends stdClass {
        },
        ], 'stdClass@anonymous',
        ];
        yield 'a closure' => [['x' => static fn (): int => 1], 'Closure'];
        yield 'a JsonSerializable inside the document' => [
            ['x' => new ExpandedDocument([])],
            ExpandedDocument::class,
        ];
    }

    public function testRejectsAResource(): void
    {
        $resource = fopen('php://memory', 'r');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('A document may hold only JSON values; resource (stream) given');

        (new DocumentReader(new Settings()))->read([$resource]);
    }

    public function testReportsAValueThatIsNotAJsonTypeBeforeAnythingElse(): void
    {
        $reader = new DocumentReader(new Settings(maxValues: 1));

        try {
            $reader->read(['a' => NAN, 'b' => "\xff", 'c' => new DateTimeImmutable()]);
            $this->fail('Expected InvalidArgument');
        } catch (InvalidArgument $exception) {
            $this->assertSame(
                'A document may hold only JSON values; DateTimeImmutable given',
                $exception->getMessage(),
            );
        }
    }

    public function testDoesNotLookForTypesDeeperThanMaxDepth(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The maxDepth limit of 1 was exceeded');

        (new DocumentReader(new Settings(maxDepth: 1)))->read([[new DateTimeImmutable()]]);
    }

    public function testLooksForTypesInTheDeepestContainerMaxDepthAllows(): void
    {
        $this->expectException(InvalidArgument::class);

        (new DocumentReader(new Settings(maxDepth: 2)))->read([[new DateTimeImmutable()]]);
    }

    public function testAnObjectThatHoldsItselfIsRefusedAtTheDepthLimit(): void
    {
        $document = new stdClass();
        $document->self = $document;

        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The maxDepth limit of 3 was exceeded');

        (new DocumentReader(new Settings(maxDepth: 3)))->read($document);
    }

    public function testLooksAgainInsideAnObjectMetAtAShallowerDepth(): void
    {
        // The shared object is met at depth 3 first, where the array it holds
        // is too deep to be walked, and then at depth 2, where it is not.
        $shared = (object) ['x' => [new DateTimeImmutable()]];

        $this->expectException(InvalidArgument::class);

        (new DocumentReader(new Settings(maxDepth: 3)))->read(['a' => $shared, 'b' => ['c' => $shared]]);
    }

    public function testKeepsLookingAfterAnObjectItHasAlreadyWalked(): void
    {
        // The list is walked from its end: the shared object, then the same
        // object one level deeper, which is not walked again, and then the
        // entry that holds a value JSON has no type for.
        $shared = (object) ['x' => 1];

        $this->expectException(InvalidArgument::class);

        (new DocumentReader(new Settings()))->read([['x' => new DateTimeImmutable()], ['c' => $shared], $shared]);
    }

    public function testReadsAJsonSerializableAsWhatItSerializesTo(): void
    {
        $reader = new DocumentReader(new Settings());

        $this->assertEquals((object) ['a' => [1]], $reader->read(self::serializable(['a' => [1]])));
        $this->assertEquals((object) ['a' => 1], $reader->read(self::serializable((object) ['a' => 1])));
        $this->assertEquals((object) ['a' => 1], $reader->read(self::serializable('{"a": 1}')));
        $this->assertSame(42, $reader->read(self::serializable(42)));
        $this->assertNull($reader->read(self::serializable(null)));
        $this->assertEquals([new stdClass()], $reader->read(new ExpandedDocument([new stdClass()])));
    }

    public function testRejectsAJsonSerializableThatSerializesToAnother(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('A document may hold only JSON values; JsonSerializable@anonymous');

        (new DocumentReader(new Settings()))->read(self::serializable(self::serializable([])));
    }

    public function testRejectsAJsonSerializableThatSerializesToMalformedJson(): void
    {
        $this->expectException(JsonLdError::class);
        $this->expectExceptionMessageIsOrContains('Loading document failed: the text is not valid JSON (Syntax error)');

        (new DocumentReader(new Settings()))->read(self::serializable('{'));
    }

    public function testATopLevelObjectIsDepthOne(): void
    {
        $reader = new DocumentReader(new Settings(maxDepth: 1));

        $this->assertEquals((object) ['a' => 1], $reader->read('{"a": 1}'));
        $this->assertEquals((object) ['a' => 1], $reader->read(['a' => 1]));
    }

    public function testRejectsAStringDeeperThanMaxDepth(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The maxDepth limit of 2 was exceeded');

        (new DocumentReader(new Settings(maxDepth: 2)))->read('{"a": {"b": {}}}');
    }

    public function testDepthErrorFromTheDecoderCarriesTheLimit(): void
    {
        try {
            (new DocumentReader(new Settings(maxDepth: 3)))->read(str_repeat('[', 4) . str_repeat(']', 4));
            $this->fail('Expected LimitExceeded');
        } catch (LimitExceeded $exception) {
            $this->assertSame('maxDepth', $exception->parameter);
            $this->assertSame(3, $exception->limit);
            $this->assertNotNull($exception->getPrevious());
        }
    }

    public function testAcceptsAStringExactlyAtMaxDepth(): void
    {
        $this->assertSame([[[1]]], (new DocumentReader(new Settings(maxDepth: 3)))->read('[[[1]]]'));
    }

    public function testRejectsADecodedDocumentDeeperThanMaxDepth(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The maxDepth limit of 2 was exceeded');

        (new DocumentReader(new Settings(maxDepth: 2)))->read(['a' => ['b' => ['c' => 1]]]);
    }

    public function testRejectsADecodedObjectTreeDeeperThanMaxDepth(): void
    {
        $input = json_decode('{"a": {"b": {}}}', false, 512, JSON_THROW_ON_ERROR);

        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The maxDepth limit of 2 was exceeded');

        (new DocumentReader(new Settings(maxDepth: 2)))->read($input);
    }

    public function testRejectsADecodedListDeeperThanMaxDepth(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The maxDepth limit of 2 was exceeded');

        (new DocumentReader(new Settings(maxDepth: 2)))->read([[[1]]]);
    }

    public function testAcceptsADecodedDocumentExactlyAtMaxDepth(): void
    {
        $reader = new DocumentReader(new Settings(maxDepth: 2));

        $this->assertSame([[1]], $reader->read([[1]]));
        $this->assertEquals((object) ['a' => (object) []], $reader->read(['a' => (object) []]));
    }

    public function testTheLargestDepthTheDecoderAcceptsStillDecodes(): void
    {
        $this->assertSame([1], (new DocumentReader(new Settings(maxDepth: 2_147_483_647)))->read('[1]'));
        $this->assertSame([1], (new DocumentReader(new Settings(maxDepth: PHP_INT_MAX)))->read('[1]'));
    }

    public function testCountsEveryValueIncludingScalarsAndContainers(): void
    {
        // The object, the number, the list, and the two items in it: five values.
        $document = '{"a": 1, "b": [true, null]}';

        $this->assertEquals(
            (object) ['a' => 1, 'b' => [true, null]],
            (new DocumentReader(new Settings(maxValues: 5)))->read($document),
        );

        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The maxValues limit of 4 was exceeded');

        (new DocumentReader(new Settings(maxValues: 4)))->read($document);
    }

    public function testCountsValuesOfADecodedDocument(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessageIsOrContains('The maxValues limit of 2 was exceeded');

        (new DocumentReader(new Settings(maxValues: 2)))->read(['a' => 1, 'b' => 2]);
    }

    public function testCountsAreResetBetweenReads(): void
    {
        $reader = new DocumentReader(new Settings(maxValues: 3));

        $this->assertSame([1, 2], $reader->read('[1, 2]'));
        $this->assertSame([1, 2], $reader->read('[1, 2]'));
    }

    public function testAMaximumIntegerLimitDisablesTheCheck(): void
    {
        $reader = new DocumentReader(new Settings(maxDepth: PHP_INT_MAX, maxValues: PHP_INT_MAX));

        $this->assertSame([[[[1]]]], $reader->read('[[[[1]]]]'));
    }

    private static function serializable(mixed $value): JsonSerializable
    {
        return new class ($value) implements JsonSerializable {
            public function __construct(private readonly mixed $value)
            {
            }

            public function jsonSerialize(): mixed
            {
                return $this->value;
            }
        };
    }
}
