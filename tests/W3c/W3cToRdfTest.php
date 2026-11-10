<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\DataLossReason;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\JsonLdErrorCode;
use SocialWeb\Rdf\Canonicalization\Canonicalizer;
use SocialWeb\Rdf\Dataset;
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
final class W3cToRdfTest extends TestCase
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
        '#t0030' => DataLossReason::FreeFloatingNode,
        '#tc014' => DataLossReason::UndefinedProperty,
        '#tc018' => DataLossReason::UndefinedProperty,
        '#tdi01' => DataLossReason::DroppedDirection,
        '#tdi02' => DataLossReason::DroppedDirection,
        '#tdi03' => DataLossReason::DroppedDirection,
        '#tdi04' => DataLossReason::DroppedDirection,
        '#tdi05' => DataLossReason::DroppedDirection,
        '#tdi06' => DataLossReason::DroppedDirection,
        '#te001' => DataLossReason::FreeFloatingNode,
        '#te003' => DataLossReason::UndefinedProperty,
        '#te004' => DataLossReason::NullValue,
        '#te005' => DataLossReason::ReservedTerm,
        '#te008' => DataLossReason::FreeFloatingValue,
        '#te016' => DataLossReason::UndefinedProperty,
        '#te019' => DataLossReason::NullValue,
        '#te032' => DataLossReason::UndefinedProperty,
        '#te036' => DataLossReason::NullValue,
        '#te045' => DataLossReason::FreeFloatingValue,
        '#te046' => DataLossReason::FreeFloatingNode,
        '#te047' => DataLossReason::FreeFloatingNode,
        '#te059' => DataLossReason::UndefinedProperty,
        '#te060' => DataLossReason::UndefinedProperty,
        '#te065' => DataLossReason::UndefinedProperty,
        '#te068' => DataLossReason::BlankNodePredicate,
        '#te111' => DataLossReason::RelativePredicate,
        '#te112' => DataLossReason::RelativePredicate,
        '#te119' => DataLossReason::ReservedTerm,
        '#te120' => DataLossReason::ReservedTerm,
        '#te122' => DataLossReason::ReservedTerm,
        '#tin06' => DataLossReason::UndefinedProperty,
        '#tli12' => DataLossReason::RelativeObject,
        '#tli14' => DataLossReason::RelativeObject,
        '#tpr06' => DataLossReason::UndefinedProperty,
        '#tpr19' => DataLossReason::UndefinedProperty,
        '#tpr34' => DataLossReason::ReservedTerm,
        '#tpr35' => DataLossReason::ReservedTerm,
        '#tpr36' => DataLossReason::ReservedTerm,
        '#tpr37' => DataLossReason::ReservedTerm,
        '#tpr38' => DataLossReason::ReservedTerm,
        '#tpr39' => DataLossReason::ReservedTerm,
        '#twf01' => DataLossReason::RelativeSubject,
        '#twf02' => DataLossReason::RelativePredicate,
        '#twf03' => DataLossReason::RelativeSubject,
        '#twf04' => DataLossReason::RelativeType,
        '#twf05' => DataLossReason::MalformedLanguageTag,
        '#twf07' => DataLossReason::RelativeSubject,
    ];

    /**
     * The negative entries that raise `DataLoss` in strict mode, with the
     * condition each one raises, because the algorithm drops something before
     * it gets to the step that raises the expected error. Every other negative
     * entry raises the same error in both modes.
     */
    private const array STRICT_DATA_LOSS_BEFORE_ERROR = [
        '#tin07' => DataLossReason::FreeFloatingNode,
        '#tin08' => DataLossReason::FreeFloatingValue,
        '#tin09' => DataLossReason::FreeFloatingNode,
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
                $dataLoss->reason,
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
            $this->assertSame(JsonLdErrorCode::from($entry->expectErrorCode), $error->reason, $error->getMessage());

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
            $this->assertSame(JsonLdErrorCode::from($entry->expectErrorCode), $error->reason, $error->getMessage());

            return;
        } catch (DataLoss $dataLoss) {
            $this->assertSame(
                self::STRICT_DATA_LOSS_BEFORE_ERROR[$entry->id] ?? null,
                $dataLoss->reason,
                $dataLoss->getMessage(),
            );

            return;
        }

        $this->fail('Expected a JsonLdError with the code "' . $entry->expectErrorCode . '"');
    }

    /**
     * A positive syntax entry has no expected output; conversion must
     * complete in both modes and give the same dataset
     */
    #[DataProvider('positiveSyntaxEntries')]
    public function testConvertsInBothModes(W3cEntry $entry): void
    {
        $input = W3cManifest::read($entry->input);

        $lenient = W3cProcessor::build($entry, strict: false)->toRdf($input)->toNQuads();
        $strict = W3cProcessor::build($entry, strict: true)->toRdf($input)->toNQuads();

        $this->assertNotSame('', $lenient);
        $this->assertSame($lenient, $strict);
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
        return self::canonical(Dataset::fromNQuads(W3cManifest::read($path)));
    }

    private static function canonical(Dataset $dataset): string
    {
        return (new Canonicalizer(workFactor: self::WORK_FACTOR))->canonicalize($dataset)->toNQuads();
    }
}
