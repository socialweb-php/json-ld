<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\Test\JsonLd\TestCase;

class FixtureDocumentLoaderTest extends TestCase
{
    public function testLoadsAFixtureByTheUrlItIsPublishedAt(): void
    {
        $url = W3cManifest::BASE_IRI . 'expand/0127-context-1.jsonld';

        $loaded = (new FixtureDocumentLoader())->load($url);

        $this->assertSame($url, $loaded->documentUrl);
        $this->assertIsObject($loaded->document);
        $this->assertObjectHasProperty('@context', $loaded->document);
    }

    #[DataProvider('unknownUrls')]
    public function testKnowsNothingElse(string $url): void
    {
        $this->expectExceptionObject(new JsonLdError(ErrorCode::LoadingRemoteContextFailed, $url));

        (new FixtureDocumentLoader())->load($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownUrls(): iterable
    {
        yield 'a file that does not exist' => [W3cManifest::BASE_IRI . 'expand/missing-context.jsonld'];
        yield 'a directory' => [W3cManifest::BASE_IRI . 'expand'];
        yield 'another site' => ['https://example.com/expand/0127-context-1.jsonld'];
        yield 'a pinned context' => ['https://www.w3.org/ns/activitystreams'];
        yield 'a path that climbs out of the fixtures' => [W3cManifest::BASE_IRI . '../jcs/input/arrays.json'];
    }
}
