<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\Rdf\Canonicalization\Canonicalizer;
use SocialWeb\Rdf\Dataset;
use SocialWeb\Rdf\NQuads\Parser;
use SocialWeb\Test\JsonLd\TestCase;

use function array_key_exists;
use function array_keys;
use function iterator_to_array;

/**
 * Runs the RDF conversion tests of the W3C JSON-LD API test suite, in lenient
 * mode and in strict mode
 *
 * A dataset is compared with the expected one by canonicalizing both with the
 * RDF package and comparing the canonical N-Quads, so blank node labels do
 * not matter.
 *
 * @link https://w3c.github.io/json-ld-api/tests/
 */
class W3cToRdfTest extends TestCase
{
    /**
     * The positive entries that strict mode refuses, with the condition each
     * one raises. Every other positive entry converts to the same dataset in
     * both modes. Thirty are the entries the expand suite refuses too, under
     * the identifiers of this manifest; the other seventeen are conditions of
     * conversion to RDF. In `#te111` and `#te112` the predicate holds two `#`
     * characters, which is not a well-formed IRI.
     */
    private const array STRICT_DATA_LOSS = [
        '#t0030' => DataLossCondition::FreeFloatingNode,
        '#tc014' => DataLossCondition::UndefinedProperty,
        '#tc018' => DataLossCondition::UndefinedProperty,
        '#tdi01' => DataLossCondition::DroppedDirection,
        '#tdi02' => DataLossCondition::DroppedDirection,
        '#tdi03' => DataLossCondition::DroppedDirection,
        '#tdi04' => DataLossCondition::DroppedDirection,
        '#tdi05' => DataLossCondition::DroppedDirection,
        '#tdi06' => DataLossCondition::DroppedDirection,
        '#te001' => DataLossCondition::FreeFloatingNode,
        '#te003' => DataLossCondition::UndefinedProperty,
        '#te004' => DataLossCondition::NullValue,
        '#te005' => DataLossCondition::ReservedTerm,
        '#te008' => DataLossCondition::FreeFloatingValue,
        '#te016' => DataLossCondition::UndefinedProperty,
        '#te019' => DataLossCondition::NullValue,
        '#te032' => DataLossCondition::UndefinedProperty,
        '#te036' => DataLossCondition::NullValue,
        '#te045' => DataLossCondition::FreeFloatingValue,
        '#te046' => DataLossCondition::FreeFloatingNode,
        '#te047' => DataLossCondition::FreeFloatingNode,
        '#te059' => DataLossCondition::UndefinedProperty,
        '#te060' => DataLossCondition::UndefinedProperty,
        '#te065' => DataLossCondition::UndefinedProperty,
        '#te068' => DataLossCondition::BlankNodePredicate,
        '#te111' => DataLossCondition::RelativePredicate,
        '#te112' => DataLossCondition::RelativePredicate,
        '#te119' => DataLossCondition::ReservedTerm,
        '#te120' => DataLossCondition::ReservedTerm,
        '#te122' => DataLossCondition::ReservedTerm,
        '#tin06' => DataLossCondition::UndefinedProperty,
        '#tli12' => DataLossCondition::RelativeObject,
        '#tli14' => DataLossCondition::RelativeObject,
        '#tpr06' => DataLossCondition::UndefinedProperty,
        '#tpr19' => DataLossCondition::UndefinedProperty,
        '#tpr34' => DataLossCondition::ReservedTerm,
        '#tpr35' => DataLossCondition::ReservedTerm,
        '#tpr36' => DataLossCondition::ReservedTerm,
        '#tpr37' => DataLossCondition::ReservedTerm,
        '#tpr38' => DataLossCondition::ReservedTerm,
        '#tpr39' => DataLossCondition::ReservedTerm,
        '#twf01' => DataLossCondition::RelativeSubject,
        '#twf02' => DataLossCondition::RelativePredicate,
        '#twf03' => DataLossCondition::RelativeSubject,
        '#twf04' => DataLossCondition::RelativeType,
        '#twf05' => DataLossCondition::MalformedLanguageTag,
        '#twf07' => DataLossCondition::RelativeSubject,
    ];

    /**
     * The negative entries that raise `DataLoss` in strict mode, with the
     * condition each one raises, because the algorithm drops something before
     * it gets to the step that raises the expected error. Every other negative
     * entry raises the same error in both modes.
     */
    private const array STRICT_DATA_LOSS_BEFORE_ERROR = [
        '#tin07' => DataLossCondition::FreeFloatingNode,
        '#tin08' => DataLossCondition::FreeFloatingValue,
        '#tin09' => DataLossCondition::FreeFloatingNode,
    ];

    /**
     * The positive entries that are not run, each with the reason
     */
    private const array SKIPPED = [
        '#te075' => 'It requires generalized RDF, and a quad cannot hold a blank node predicate',
    ];

    /**
     * The canonicalizer's default work factor is too small for two of the
     * expected datasets, `#te027` and `#te062`, whose lists hold items that
     * repeat; a work factor of 2 allows every expected dataset.
     */
    private const int WORK_FACTOR = 2;

    #[DataProvider('positiveEntries')]
    public function testConvertsInLenientMode(W3cEntry $entry): void
    {
        $this->assertNotNull($entry->expect);

        $dataset = W3cProcessor::build($entry, strict: false)->toRdf(W3cManifest::read($entry->input));

        $this->assertSame(self::expected($entry->expect), self::canonical($dataset));
    }

    #[DataProvider('positiveEntries')]
    public function testConvertsToTheSameDatasetInStrictModeOrRefuses(W3cEntry $entry): void
    {
        $this->assertNotNull($entry->expect);

        try {
            $dataset = W3cProcessor::build($entry, strict: true)->toRdf(W3cManifest::read($entry->input));
        } catch (DataLoss $dataLoss) {
            $this->assertSame(
                self::STRICT_DATA_LOSS[$entry->id] ?? null,
                $dataLoss->condition,
                $dataLoss->getMessage(),
            );

            return;
        }

        $this->assertArrayNotHasKey($entry->id, self::STRICT_DATA_LOSS, 'Strict mode was expected to refuse');
        $this->assertSame(self::expected($entry->expect), self::canonical($dataset));
    }

    #[DataProvider('negativeEntries')]
    public function testRejectsInLenientMode(W3cEntry $entry): void
    {
        $this->assertNotNull($entry->expectErrorCode);

        try {
            W3cProcessor::build($entry, strict: false)->toRdf(W3cManifest::read($entry->input));
        } catch (JsonLdError $error) {
            $this->assertSame(ErrorCode::from($entry->expectErrorCode), $error->errorCode, $error->getMessage());

            return;
        }

        $this->fail('Expected a JsonLdError with the code "' . $entry->expectErrorCode . '"');
    }

    #[DataProvider('negativeEntries')]
    public function testRejectsInStrictMode(W3cEntry $entry): void
    {
        $this->assertNotNull($entry->expectErrorCode);

        try {
            W3cProcessor::build($entry, strict: true)->toRdf(W3cManifest::read($entry->input));
        } catch (JsonLdError $error) {
            $this->assertArrayNotHasKey($entry->id, self::STRICT_DATA_LOSS_BEFORE_ERROR);
            $this->assertSame(ErrorCode::from($entry->expectErrorCode), $error->errorCode, $error->getMessage());

            return;
        } catch (DataLoss $dataLoss) {
            $this->assertSame(
                self::STRICT_DATA_LOSS_BEFORE_ERROR[$entry->id] ?? null,
                $dataLoss->condition,
                $dataLoss->getMessage(),
            );

            return;
        }

        $this->fail('Expected a JsonLdError with the code "' . $entry->expectErrorCode . '"');
    }

    /**
     * A positive syntax entry has no expected output; conversion must
     * complete, in both modes
     */
    #[DataProvider('positiveSyntaxEntries')]
    public function testConvertsInBothModes(W3cEntry $entry): void
    {
        $input = W3cManifest::read($entry->input);

        $this->assertCount(1, W3cProcessor::build($entry, strict: false)->toRdf($input));
        $this->assertCount(1, W3cProcessor::build($entry, strict: true)->toRdf($input));
    }

    public function testTheListsNameEntriesOfTheManifest(): void
    {
        $positive = iterator_to_array(W3cManifest::entries(W3cManifest::TO_RDF, W3cManifest::POSITIVE));
        $negative = iterator_to_array(self::negativeEntries());

        foreach (array_keys(self::STRICT_DATA_LOSS) as $id) {
            $this->assertArrayHasKey($id, $positive);
            $this->assertArrayNotHasKey($id, self::SKIPPED);
        }

        foreach (array_keys(self::STRICT_DATA_LOSS_BEFORE_ERROR) as $id) {
            $this->assertArrayHasKey($id, $negative);
        }

        foreach (array_keys(self::SKIPPED) as $id) {
            $this->assertArrayHasKey($id, $positive);
        }
    }

    /**
     * @return iterable<string, array{W3cEntry}>
     */
    public static function positiveEntries(): iterable
    {
        foreach (W3cManifest::entries(W3cManifest::TO_RDF, W3cManifest::POSITIVE) as $id => $arguments) {
            if (!array_key_exists($id, self::SKIPPED)) {
                yield $id => $arguments;
            }
        }
    }

    /**
     * @return iterable<string, array{W3cEntry}>
     */
    public static function negativeEntries(): iterable
    {
        return W3cManifest::entries(W3cManifest::TO_RDF, W3cManifest::NEGATIVE);
    }

    /**
     * @return iterable<string, array{W3cEntry}>
     */
    public static function positiveSyntaxEntries(): iterable
    {
        return W3cManifest::entries(W3cManifest::TO_RDF, W3cManifest::POSITIVE_SYNTAX);
    }

    private static function expected(string $path): string
    {
        return self::canonical((new Parser())->parse(W3cManifest::read($path)));
    }

    private static function canonical(Dataset $dataset): string
    {
        return (new Canonicalizer(workFactor: self::WORK_FACTOR))->canonicalize($dataset)->toNQuads();
    }
}
