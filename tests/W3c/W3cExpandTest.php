<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\DataLossReason;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\JsonLdErrorCode;
use SocialWeb\Test\JsonLd\TestCase;

use function array_keys;
use function iterator_to_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Runs the expansion tests of the W3C JSON-LD API test suite, in lenient mode
 * and in strict mode
 *
 * @link https://w3c.github.io/json-ld-api/tests/
 */
final class W3cExpandTest extends TestCase
{
    /**
     * The positive entries that strict mode refuses, with the condition each
     * one raises. Every other positive entry expands to the same result in
     * both modes.
     */
    private const array STRICT_DATA_LOSS = [
        '#t0001' => DataLossReason::FreeFloatingNode,
        '#t0003' => DataLossReason::UndefinedProperty,
        '#t0004' => DataLossReason::NullValue,
        '#t0005' => DataLossReason::ReservedTerm,
        '#t0008' => DataLossReason::FreeFloatingValue,
        '#t0014' => DataLossReason::NullValue,
        '#t0016' => DataLossReason::UndefinedProperty,
        '#t0019' => DataLossReason::NullValue,
        '#t0032' => DataLossReason::UndefinedProperty,
        '#t0036' => DataLossReason::NullValue,
        '#t0045' => DataLossReason::FreeFloatingValue,
        '#t0046' => DataLossReason::FreeFloatingNode,
        '#t0047' => DataLossReason::FreeFloatingNode,
        '#t0059' => DataLossReason::UndefinedProperty,
        '#t0060' => DataLossReason::UndefinedProperty,
        '#t0065' => DataLossReason::UndefinedProperty,
        '#t0119' => DataLossReason::ReservedTerm,
        '#t0120' => DataLossReason::ReservedTerm,
        '#t0122' => DataLossReason::ReservedTerm,
        '#tc014' => DataLossReason::UndefinedProperty,
        '#tc018' => DataLossReason::UndefinedProperty,
        '#tin06' => DataLossReason::UndefinedProperty,
        '#tpr06' => DataLossReason::UndefinedProperty,
        '#tpr19' => DataLossReason::UndefinedProperty,
        '#tpr34' => DataLossReason::ReservedTerm,
        '#tpr35' => DataLossReason::ReservedTerm,
        '#tpr36' => DataLossReason::ReservedTerm,
        '#tpr37' => DataLossReason::ReservedTerm,
        '#tpr38' => DataLossReason::ReservedTerm,
        '#tpr39' => DataLossReason::ReservedTerm,
    ];

    /**
     * The negative entries that raise `DataLoss` in strict mode, with the
     * condition each one raises. In these entries, the algorithm drops
     * something before it gets to the step that raises the expected error, so
     * strict mode stops at the drop. Every other negative entry raises the same
     * error in both modes.
     */
    private const array STRICT_DATA_LOSS_BEFORE_ERROR = [
        '#tin07' => DataLossReason::FreeFloatingNode,
        '#tin08' => DataLossReason::FreeFloatingValue,
        '#tin09' => DataLossReason::FreeFloatingNode,
    ];

    #[DataProvider('positiveEntries')]
    public function testExpandsInLenientMode(W3cEntry $entry): void
    {
        $this->assertNotNull($entry->expect);

        $expanded = W3cProcessor::build($entry, strict: false)->expand(W3cManifest::read($entry->input));

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
            $expanded = W3cProcessor::build($entry, strict: true)->expand(W3cManifest::read($entry->input));
        } catch (DataLoss $dataLoss) {
            $this->assertSame(
                self::STRICT_DATA_LOSS[$entry->id] ?? null,
                $dataLoss->reason,
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
            W3cProcessor::build($entry, strict: false)->expand(W3cManifest::read($entry->input));
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
            W3cProcessor::build($entry, strict: true)->expand(W3cManifest::read($entry->input));
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
}
