<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\Rdf\Dataset;
use SocialWeb\Rdf\NQuads\Serializer;
use SocialWeb\Test\JsonLd\W3c\W3cEntry;
use SocialWeb\Test\JsonLd\W3c\W3cManifest;
use SocialWeb\Test\JsonLd\W3c\W3cProcessor;

use function is_array;
use function is_object;
use function json_decode;
use function preg_match;

use const JSON_THROW_ON_ERROR;

/**
 * Properties that hold for every positive RDF conversion entry of the W3C
 * suite, positive syntax entries included
 *
 * Datasets are compared as N-Quads in the order the converter adds the quads,
 * labels included, because every path goes through the one converter and the
 * output does not depend on the form the document came in.
 */
class ConversionPropertyTest extends TestCase
{
    #[DataProvider('entries')]
    public function testConvertingTheExpandedDocumentGivesTheDatasetOfItsSource(W3cEntry $entry): void
    {
        $processor = W3cProcessor::build($entry, strict: false);
        $json = W3cManifest::read($entry->input);

        $this->assertSame(
            self::nquads($processor->toRdf($json)),
            self::nquads($processor->toRdf($processor->expand($json))),
        );
    }

    #[DataProvider('entries')]
    public function testAStringAndItsDecodedFormsConvertAlike(W3cEntry $entry): void
    {
        $json = W3cManifest::read($entry->input);
        $asObjects = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $asArrays = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $processor = W3cProcessor::build($entry, strict: false);
        $expected = self::nquads($processor->toRdf($json));

        if (is_array($asObjects) || is_object($asObjects)) {
            $this->assertSame($expected, self::nquads($processor->toRdf($asObjects)));
        }

        // Decoding to arrays turns an empty object into an empty array, which is a different document.
        if (is_array($asArrays) && preg_match('/\{\s*\}/', $json) === 0) {
            $this->assertSame($expected, self::nquads($processor->toRdf($asArrays)));
        }
    }

    /**
     * @return iterable<string, array{W3cEntry}>
     */
    public static function entries(): iterable
    {
        yield from W3cManifest::entries(W3cManifest::TO_RDF, W3cManifest::POSITIVE);
        yield from W3cManifest::entries(W3cManifest::TO_RDF, W3cManifest::POSITIVE_SYNTAX);
    }

    private static function nquads(Dataset $dataset): string
    {
        return (new Serializer())->serialize($dataset);
    }
}
