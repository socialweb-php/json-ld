<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\ProcessingMode;
use SocialWeb\JsonLd\Processor;
use SocialWeb\Test\JsonLd\W3c\FixtureDocumentLoader;
use SocialWeb\Test\JsonLd\W3c\W3cEntry;
use SocialWeb\Test\JsonLd\W3c\W3cManifest;

use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function json_decode;
use function preg_match;

use const JSON_THROW_ON_ERROR;

/**
 * Properties that hold for every positive expansion entry of the W3C suite
 */
class ExpansionPropertyTest extends TestCase
{
    /**
     * The entries whose lenient output cannot be expanded again
     *
     * In `#t0122` an `@id` has the form of a keyword. Lenient mode keeps the
     * entry as `"@id": null`, and an `@id` of null is not valid input, so
     * expanding the output a second time raises an error. Strict mode refuses
     * the document the first time.
     */
    private const array NOT_VALID_AS_INPUT = ['#t0122'];

    #[DataProvider('entries')]
    public function testExpandingAnExpandedDocumentChangesNothing(W3cEntry $entry): void
    {
        $expanded = self::processor($entry)->expand(W3cManifest::read($entry->input));
        $again = new Processor(new Options(processingMode: self::processingMode($entry), strict: false));

        $this->assertSame($expanded->toJson(), $again->expand($expanded->toJson())->toJson());
        $this->assertSame($expanded->toJson(), $again->expand($expanded->jsonSerialize())->toJson());
    }

    #[DataProvider('entries')]
    public function testAStringAndItsDecodedFormsExpandAlike(W3cEntry $entry): void
    {
        $json = W3cManifest::read($entry->input);
        $asObjects = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $asArrays = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $expected = self::processor($entry)->expand($json)->toJson();

        if (is_array($asObjects) || is_object($asObjects)) {
            $this->assertSame($expected, self::processor($entry)->expand($asObjects)->toJson());
        }

        // Decoding to arrays turns an empty object into an empty array, which is a different document.
        if (is_array($asArrays) && preg_match('/\{\s*\}/', $json) === 0) {
            $this->assertSame($expected, self::processor($entry)->expand($asArrays)->toJson());
        }
    }

    /**
     * @return iterable<string, array{W3cEntry}>
     */
    public static function entries(): iterable
    {
        foreach (W3cManifest::entries(W3cManifest::EXPAND, W3cManifest::POSITIVE) as $id => $arguments) {
            if (!in_array($id, self::NOT_VALID_AS_INPUT, true)) {
                yield $id => $arguments;
            }
        }
    }

    private static function processor(W3cEntry $entry): Processor
    {
        $base = $entry->option['base'] ?? null;
        $expandContext = $entry->option['expandContext'] ?? null;

        return new Processor(new Options(
            base: is_string($base) ? $base : $entry->inputUrl,
            expandContext: is_string($expandContext) ? W3cManifest::BASE_IRI . $expandContext : null,
            processingMode: self::processingMode($entry),
            strict: false,
            documentLoader: new FixtureDocumentLoader(),
        ));
    }

    private static function processingMode(W3cEntry $entry): ProcessingMode
    {
        $processingMode = $entry->option['processingMode'] ?? null;

        return is_string($processingMode) ? ProcessingMode::from($processingMode) : ProcessingMode::JsonLd11;
    }
}
