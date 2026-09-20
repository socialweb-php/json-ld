<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\ProcessingMode;
use SocialWeb\JsonLd\Processor;
use SocialWeb\Test\JsonLd\TestCase;

use function array_keys;
use function is_string;
use function iterator_to_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Runs the expansion tests of the W3C JSON-LD API test suite, in lenient mode
 * and in strict mode
 *
 * @link https://w3c.github.io/json-ld-api/tests/
 */
class W3cExpandTest extends TestCase
{
    /**
     * The positive entries that strict mode refuses, with the condition each
     * one raises. Every other positive entry expands to the same result in
     * both modes.
     */
    private const array STRICT_DATA_LOSS = [
        '#t0001' => DataLossCondition::FreeFloatingNode,
        '#t0003' => DataLossCondition::UndefinedProperty,
        '#t0004' => DataLossCondition::NullValue,
        '#t0005' => DataLossCondition::ReservedTerm,
        '#t0008' => DataLossCondition::FreeFloatingValue,
        '#t0014' => DataLossCondition::NullValue,
        '#t0016' => DataLossCondition::UndefinedProperty,
        '#t0019' => DataLossCondition::NullValue,
        '#t0032' => DataLossCondition::UndefinedProperty,
        '#t0036' => DataLossCondition::NullValue,
        '#t0045' => DataLossCondition::FreeFloatingValue,
        '#t0046' => DataLossCondition::FreeFloatingNode,
        '#t0047' => DataLossCondition::FreeFloatingNode,
        '#t0059' => DataLossCondition::UndefinedProperty,
        '#t0060' => DataLossCondition::UndefinedProperty,
        '#t0065' => DataLossCondition::UndefinedProperty,
        '#t0119' => DataLossCondition::ReservedTerm,
        '#t0120' => DataLossCondition::ReservedTerm,
        '#t0122' => DataLossCondition::ReservedTerm,
        '#tc014' => DataLossCondition::UndefinedProperty,
        '#tc018' => DataLossCondition::UndefinedProperty,
        '#tin06' => DataLossCondition::UndefinedProperty,
        '#tpr06' => DataLossCondition::UndefinedProperty,
        '#tpr19' => DataLossCondition::UndefinedProperty,
        '#tpr34' => DataLossCondition::ReservedTerm,
        '#tpr35' => DataLossCondition::ReservedTerm,
        '#tpr36' => DataLossCondition::ReservedTerm,
        '#tpr37' => DataLossCondition::ReservedTerm,
        '#tpr38' => DataLossCondition::ReservedTerm,
        '#tpr39' => DataLossCondition::ReservedTerm,
    ];

    /**
     * The negative entries that raise `DataLoss` in strict mode, with the
     * condition each one raises. In these entries, the algorithm drops
     * something before it gets to the step that raises the expected error, so
     * strict mode stops at the drop. Every other negative entry raises the same
     * error in both modes.
     */
    private const array STRICT_DATA_LOSS_BEFORE_ERROR = [
        '#tin07' => DataLossCondition::FreeFloatingNode,
        '#tin08' => DataLossCondition::FreeFloatingValue,
        '#tin09' => DataLossCondition::FreeFloatingNode,
    ];

    #[DataProvider('positiveEntries')]
    public function testExpandsInLenientMode(W3cEntry $entry): void
    {
        $this->assertNotNull($entry->expect);

        $expanded = self::processor($entry, strict: false)->expand(W3cManifest::read($entry->input));

        $this->assertSame(
            JsonLdComparison::canonicalize(json_decode(W3cManifest::read($entry->expect), flags: JSON_THROW_ON_ERROR)),
            JsonLdComparison::canonicalize($expanded->jsonSerialize()),
        );
    }

    #[DataProvider('positiveEntries')]
    public function testExpandsToTheSameResultInStrictModeOrRefuses(W3cEntry $entry): void
    {
        $this->assertNotNull($entry->expect);

        try {
            $expanded = self::processor($entry, strict: true)->expand(W3cManifest::read($entry->input));
        } catch (DataLoss $dataLoss) {
            $this->assertSame(
                self::STRICT_DATA_LOSS[$entry->id] ?? null,
                $dataLoss->condition,
                $dataLoss->getMessage(),
            );

            return;
        }

        $this->assertArrayNotHasKey($entry->id, self::STRICT_DATA_LOSS, 'Strict mode was expected to refuse');
        $this->assertSame(
            JsonLdComparison::canonicalize(json_decode(W3cManifest::read($entry->expect), flags: JSON_THROW_ON_ERROR)),
            JsonLdComparison::canonicalize($expanded->jsonSerialize()),
        );
    }

    #[DataProvider('negativeEntries')]
    public function testRejectsInLenientMode(W3cEntry $entry): void
    {
        $this->assertNotNull($entry->expectErrorCode);

        try {
            self::processor($entry, strict: false)->expand(W3cManifest::read($entry->input));
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
            self::processor($entry, strict: true)->expand(W3cManifest::read($entry->input));
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

    public function testTheStrictModeListsNameEntriesOfTheManifest(): void
    {
        $positive = iterator_to_array(self::positiveEntries());
        $negative = iterator_to_array(self::negativeEntries());

        foreach (array_keys(self::STRICT_DATA_LOSS) as $id) {
            $this->assertArrayHasKey($id, $positive);
        }

        foreach (array_keys(self::STRICT_DATA_LOSS_BEFORE_ERROR) as $id) {
            $this->assertArrayHasKey($id, $negative);
        }
    }

    /**
     * @return iterable<string, array{W3cEntry}>
     */
    public static function positiveEntries(): iterable
    {
        return W3cManifest::entries(W3cManifest::EXPAND, W3cManifest::POSITIVE);
    }

    /**
     * @return iterable<string, array{W3cEntry}>
     */
    public static function negativeEntries(): iterable
    {
        return W3cManifest::entries(W3cManifest::EXPAND, W3cManifest::NEGATIVE);
    }

    /**
     * The input's URL is the base, unless the entry gives another.
     */
    private static function processor(W3cEntry $entry, bool $strict): Processor
    {
        $base = $entry->option['base'] ?? null;
        $expandContext = $entry->option['expandContext'] ?? null;
        $processingMode = $entry->option['processingMode'] ?? null;

        return new Processor(new Options(
            base: is_string($base) ? $base : $entry->inputUrl,
            expandContext: is_string($expandContext) ? W3cManifest::BASE_IRI . $expandContext : null,
            processingMode: is_string($processingMode)
                ? ProcessingMode::from($processingMode)
                : ProcessingMode::JsonLd11,
            strict: $strict,
            documentLoader: new FixtureDocumentLoader(),
        ));
    }
}
