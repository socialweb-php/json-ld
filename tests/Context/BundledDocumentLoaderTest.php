<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\MalformedJson;
use SocialWeb\JsonLd\Rdf\JsonCanonicalizer;
use SocialWeb\Test\JsonLd\TestCase;
use stdClass;

use function str_repeat;

class BundledDocumentLoaderTest extends TestCase
{
    private const string URL = 'https://example.com/contexts/v1';

    public function testLoadsAPinnedContextUnderTheUrlItWasAskedFor(): void
    {
        $loader = new BundledDocumentLoader();

        $https = $loader->load('https://www.w3.org/ns/activitystreams');
        $http = $loader->load('http://www.w3.org/ns/activitystreams');

        $this->assertSame('https://www.w3.org/ns/activitystreams', $https->documentUrl);
        $this->assertSame('http://www.w3.org/ns/activitystreams', $http->documentUrl);
        $this->assertIsArray($https->document);
        $this->assertArrayHasKey('@context', $https->document);
        $this->assertSame($https->document, $http->document);
    }

    public function testLoadsTheSameDocumentEachTime(): void
    {
        $loader = new BundledDocumentLoader();

        $this->assertSame(
            $loader->load('https://w3id.org/security/v1')->document,
            $loader->load('https://w3id.org/security/v1')->document,
        );
    }

    #[DataProvider('unknownUrls')]
    public function testFailsForAUrlItDoesNotKnow(string $url): void
    {
        $loader = new BundledDocumentLoader();

        try {
            $loader->load($url);
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $error) {
            $this->assertSame(ErrorCode::LoadingRemoteContextFailed, $error->errorCode);
            $this->assertSame('loading remote context failed: ' . $url, $error->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownUrls(): iterable
    {
        yield 'unknown' => ['https://example.com/context'];
        yield 'trailing slash' => ['https://www.w3.org/ns/activitystreams/'];
        yield 'fragment' => ['https://www.w3.org/ns/activitystreams#'];
        yield 'uppercase host' => ['https://WWW.W3.ORG/ns/activitystreams'];
        yield 'file name instead of URL' => ['activitystreams'];
    }

    public function testWithReturnsANewLoaderAndLeavesTheOriginalAlone(): void
    {
        $original = new BundledDocumentLoader();
        $extended = $original->with(self::URL, '{"@context": {"name": "https://example.com/name"}}');

        $this->assertNotSame($original, $extended);

        $loaded = $extended->load(self::URL);

        $this->assertSame(self::URL, $loaded->documentUrl);
        $this->assertSame(
            '{"@context":{"name":"https://example.com/name"}}',
            JsonCanonicalizer::canonicalize($loaded->document),
        );

        $this->expectException(JsonLdError::class);

        $original->load(self::URL);
    }

    public function testWithKeepsThePinnedContextsAndEarlierAdditions(): void
    {
        $loader = (new BundledDocumentLoader())
            ->with(self::URL, ['@context' => ['a' => 'https://example.com/a']])
            ->with('https://example.com/contexts/v2', ['@context' => ['b' => 'https://example.com/b']]);

        $this->assertSame(
            '{"@context":{"a":"https://example.com/a"}}',
            JsonCanonicalizer::canonicalize($loader->load(self::URL)->document),
        );
        $this->assertSame(
            '{"@context":{"b":"https://example.com/b"}}',
            JsonCanonicalizer::canonicalize($loader->load('https://example.com/contexts/v2')->document),
        );
        $this->assertIsArray($loader->load('https://w3id.org/security/v2')->document);
    }

    public function testWithReplacesAPinnedContextForThatUrlOnly(): void
    {
        $loader = (new BundledDocumentLoader())->with('https://w3id.org/security/v1', ['@context' => []]);

        $this->assertSame(
            '{"@context":[]}',
            JsonCanonicalizer::canonicalize($loader->load('https://w3id.org/security/v1')->document),
        );
        $this->assertArrayHasKey('@context', (array) $loader->load('http://w3id.org/security/v1')->document);
        $this->assertNotSame(
            '{"@context":[]}',
            JsonCanonicalizer::canonicalize($loader->load('http://w3id.org/security/v1')->document),
        );
    }

    public function testWithReadsAnAssociativeArrayUnderTheArrayRule(): void
    {
        $loader = (new BundledDocumentLoader())->with(self::URL, ['@context' => ['a' => ['@id' => 'ex:a']], 'x' => []]);
        $document = $loader->load(self::URL)->document;

        $this->assertInstanceOf(stdClass::class, $document);
        $this->assertSame('{"@context":{"a":{"@id":"ex:a"}},"x":[]}', JsonCanonicalizer::canonicalize($document));
    }

    public function testWithAcceptsAStdClassTreeAndDoesNotShareIt(): void
    {
        $context = new stdClass();
        $context->{'@context'} = new stdClass();

        $loader = (new BundledDocumentLoader())->with(self::URL, $context);
        $context->{'@context'}->added = 'later';

        $this->assertSame('{"@context":{}}', JsonCanonicalizer::canonicalize($loader->load(self::URL)->document));
    }

    public function testWithAcceptsATopLevelJsonArray(): void
    {
        $loader = (new BundledDocumentLoader())->with(self::URL, '[{"@context": {}}]');

        $this->assertSame('[{"@context":{}}]', JsonCanonicalizer::canonicalize($loader->load(self::URL)->document));
    }

    public function testWithRejectsMalformedJson(): void
    {
        $this->expectException(MalformedJson::class);

        (new BundledDocumentLoader())->with(self::URL, '{"@context": ');
    }

    #[DataProvider('scalarDocuments')]
    public function testWithRejectsADocumentThatIsNotAnObjectOrArray(string $json): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains(
            'The document for "https://example.com/contexts/v1" must be a JSON object or a JSON array',
        );

        (new BundledDocumentLoader())->with(self::URL, $json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scalarDocuments(): iterable
    {
        yield 'string' => ['"https://example.com/other"'];
        yield 'number' => ['42'];
        yield 'true' => ['true'];
        yield 'null' => ['null'];
    }

    public function testWithDoesNotApplyTheDocumentLimits(): void
    {
        $json = str_repeat('[', 200) . str_repeat(']', 200);
        $loader = (new BundledDocumentLoader())->with(self::URL, $json);

        $this->assertSame($json, JsonCanonicalizer::canonicalize($loader->load(self::URL)->document));
    }
}
