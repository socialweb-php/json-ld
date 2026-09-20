<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use RuntimeException;
use SocialWeb\Test\JsonLd\TestCase;

use function iterator_to_array;

class W3cManifestTest extends TestCase
{
    public function testListsTheExpandEntriesWithoutThoseForJsonLd10(): void
    {
        $positive = iterator_to_array(W3cManifest::entries(W3cManifest::EXPAND, W3cManifest::POSITIVE));
        $negative = iterator_to_array(W3cManifest::entries(W3cManifest::EXPAND, W3cManifest::NEGATIVE));

        // 385 entries: 276 positive and 109 negative, of which 3 and 6 are for JSON-LD 1.0.
        $this->assertCount(273, $positive);
        $this->assertCount(103, $negative);
        $this->assertSame(
            [],
            iterator_to_array(W3cManifest::entries(W3cManifest::EXPAND, W3cManifest::POSITIVE_SYNTAX)),
        );
        $this->assertArrayNotHasKey('#t0026', $positive);
        $this->assertArrayNotHasKey('#ter24', $negative);
    }

    public function testListsTheToRdfEntriesWithoutThoseForJsonLd10(): void
    {
        // 467 entries: 345 positive, 106 negative, and 16 syntax, of which 5, 6, and 0 are for JSON-LD 1.0.
        $this->assertCount(340, iterator_to_array(W3cManifest::entries(W3cManifest::TO_RDF, W3cManifest::POSITIVE)));
        $this->assertCount(100, iterator_to_array(W3cManifest::entries(W3cManifest::TO_RDF, W3cManifest::NEGATIVE)));
        $this->assertCount(
            16,
            iterator_to_array(W3cManifest::entries(W3cManifest::TO_RDF, W3cManifest::POSITIVE_SYNTAX)),
        );
    }

    public function testDescribesAPositiveEntry(): void
    {
        $entries = iterator_to_array(W3cManifest::entries(W3cManifest::EXPAND, W3cManifest::POSITIVE));

        $this->assertArrayHasKey('#t0001', $entries);
        $this->assertEquals(
            new W3cEntry(
                id: '#t0001',
                inputUrl: 'https://w3c.github.io/json-ld-api/tests/expand/0001-in.jsonld',
                input: W3cManifest::FIXTURES . '/expand/0001-in.jsonld',
                expect: W3cManifest::FIXTURES . '/expand/0001-out.jsonld',
                expectErrorCode: null,
                option: [],
            ),
            $entries['#t0001'][0],
        );
        $this->assertArrayHasKey('#t0077', $entries);
        $this->assertSame(['expandContext' => 'expand/0077-context.jsonld'], $entries['#t0077'][0]->option);
    }

    public function testDescribesANegativeEntry(): void
    {
        $entries = iterator_to_array(W3cManifest::entries(W3cManifest::EXPAND, W3cManifest::NEGATIVE));

        $this->assertArrayHasKey('#ter01', $entries);
        $this->assertNull($entries['#ter01'][0]->expect);
        $this->assertSame('keyword redefinition', $entries['#ter01'][0]->expectErrorCode);
    }

    public function testEveryInputAndExpectedFileExists(): void
    {
        foreach ([W3cManifest::EXPAND, W3cManifest::TO_RDF] as $manifest) {
            foreach ([W3cManifest::POSITIVE, W3cManifest::NEGATIVE, W3cManifest::POSITIVE_SYNTAX] as $type) {
                foreach (W3cManifest::entries($manifest, $type) as $id => [$entry]) {
                    $this->assertFileExists($entry->input, $id);

                    if ($entry->expect !== null) {
                        $this->assertFileExists($entry->expect, $id);
                    }
                }
            }
        }
    }

    public function testFailsLoudlyWhenAFixtureIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read fixture');

        W3cManifest::read(W3cManifest::FIXTURES . '/no-such-file.jsonld');
    }
}
