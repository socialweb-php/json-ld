<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\Processor;
use SocialWeb\JsonLd\Restrictions;
use SocialWeb\Rdf\Canonicalization\Canonicalizer;
use SocialWeb\Test\JsonLd\W3c\W3cManifest;

use function hash;

/**
 * Converts the published test vector of the EdDSA cryptosuite
 * `eddsa-rdfc-2022` and compares the canonical N-Quads and their hash with
 * the ones the Recommendation publishes
 *
 * The vector's two contexts are not among the pinned ones, so the test adds
 * them to the loader, which is how a caller adds a context the package does
 * not ship. The processor is strict and every restriction is on, as a caller
 * that signs documents would have it.
 *
 * @link https://www.w3.org/TR/vc-di-eddsa/#representation-eddsa-rdfc-2022 Data Integrity EdDSA Cryptosuites v1.0, B.1
 */
class PipelineTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/fixtures/vc-di-eddsa';

    #[DataProvider('vectors')]
    public function testReproducesThePublishedCanonicalFormAndHash(
        string $document,
        string $expected,
        string $hash,
    ): void {
        $loader = (new BundledDocumentLoader())
            ->with(
                'https://www.w3.org/ns/credentials/v2',
                W3cManifest::read(self::FIXTURES . '/contexts/credentials-v2.jsonld'),
            )
            ->with(
                'https://www.w3.org/ns/credentials/examples/v2',
                W3cManifest::read(self::FIXTURES . '/contexts/credentials-examples-v2.jsonld'),
            );
        $processor = new Processor(new Options(restrictions: Restrictions::all(), documentLoader: $loader));

        $canonical = (new Canonicalizer())
            ->canonicalize($processor->toRdf(W3cManifest::read(self::FIXTURES . '/' . $document)))
            ->toNQuads();

        $this->assertSame(W3cManifest::read(self::FIXTURES . '/' . $expected), $canonical);
        $this->assertSame($hash, hash('sha256', $canonical));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function vectors(): iterable
    {
        yield 'the credential without proof' => [
            'credential.jsonld',
            'credential.nq',
            '517744132ae165a5349155bef0bb0cf2258fff99dfe1dbd914b938d775a36017',
        ];
        yield 'the proof options document' => [
            'proof-options.jsonld',
            'proof-options.nq',
            'bea7b7acfbad0126b135104024a5f1733e705108f42d59668b05c0c50004c6b0',
        ];
    }
}
