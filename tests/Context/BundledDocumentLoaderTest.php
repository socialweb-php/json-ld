<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\JsonLdErrorCode;
use SocialWeb\JsonLd\ExpandedDocument;
use SocialWeb\JsonLd\Rdf\JsonCanonicalizer;
use SocialWeb\Test\JsonLd\TestCase;
use stdClass;

use function str_repeat;

final class BundledDocumentLoaderTest extends TestCase
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
            $this->assertSame(JsonLdErrorCode::LoadingRemoteContextFailed, $error->reason);
            $this->assertSame('Loading remote context failed: "' . $url . '"', $error->getMessage());
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

    public function testWithDocumentReturnsANewLoaderAndLeavesTheOriginalAlone(): void
    {
        $original = new BundledDocumentLoader();
        $extended = $original->withDocument(self::URL, '{"@context": {"name": "https://example.com/name"}}');

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

    public function testWithDocumentKeepsThePinnedContextsAndEarlierAdditions(): void
    {
        $loader = (new BundledDocumentLoader())
            ->withDocument(self::URL, ['@context' => ['a' => 'https://example.com/a']])
            ->withDocument('https://example.com/contexts/v2', ['@context' => ['b' => 'https://example.com/b']]);

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

    public function testWithDocumentKeepsEveryDocumentWhenAUrlIsANumericString(): void
    {
        $loader = (new BundledDocumentLoader())
            ->withDocument('5', ['@context' => ['a' => 'https://example.com/a']])
            ->withDocument('6', ['@context' => ['b' => 'https://example.com/b']]);

        $this->assertSame(
            '{"@context":{"a":"https://example.com/a"}}',
            JsonCanonicalizer::canonicalize($loader->load('5')->document),
        );
        $this->assertSame(
            '{"@context":{"b":"https://example.com/b"}}',
            JsonCanonicalizer::canonicalize($loader->load('6')->document),
        );
    }

    public function testWithDocumentReplacesTheDocumentOfANumericUrlAndKeepsOneEntry(): void
    {
        $loader = (new BundledDocumentLoader())
            ->withDocument('5', ['@context' => ['a' => 'https://example.com/a']])
            ->withDocument('5', ['@context' => ['b' => 'https://example.com/b']]);

        $this->assertSame(
            '{"@context":{"b":"https://example.com/b"}}',
            JsonCanonicalizer::canonicalize($loader->load('5')->document),
        );

        $this->expectExceptionObject(new JsonLdError(JsonLdErrorCode::LoadingRemoteContextFailed, '"0"'));

        $loader->load('0');
    }

    #[DataProvider('emptyDocuments')]
    public function testWithDocumentStoresAnEmptyDocument(string $json): void
    {
        $loader = (new BundledDocumentLoader())->withDocument(self::URL, $json);

        $this->assertSame($json, JsonCanonicalizer::canonicalize($loader->load(self::URL)->document));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyDocuments(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'empty array' => ['[]'];
    }

    public function testWithDocumentReplacesAPinnedContextForThatUrlOnly(): void
    {
        $loader = (new BundledDocumentLoader())->withDocument('https://w3id.org/security/v1', ['@context' => []]);

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

    public function testWithDocumentReadsAnAssociativeArrayUnderTheArrayRule(): void
    {
        $loader = (new BundledDocumentLoader())->withDocument(
            self::URL,
            ['@context' => ['a' => ['@id' => 'ex:a']], 'x' => []],
        );
        $document = $loader->load(self::URL)->document;

        $this->assertInstanceOf(stdClass::class, $document);
        $this->assertSame('{"@context":{"a":{"@id":"ex:a"}},"x":[]}', JsonCanonicalizer::canonicalize($document));
    }

    public function testWithDocumentAcceptsAStdClassTreeAndDoesNotShareIt(): void
    {
        $context = new stdClass();
        $context->{'@context'} = new stdClass();

        $loader = (new BundledDocumentLoader())->withDocument(self::URL, $context);
        $context->{'@context'}->added = 'later';

        $this->assertSame('{"@context":{}}', JsonCanonicalizer::canonicalize($loader->load(self::URL)->document));
    }

    public function testWithDocumentAcceptsATopLevelJsonArray(): void
    {
        $loader = (new BundledDocumentLoader())->withDocument(self::URL, '[{"@context": {}}]');

        $this->assertSame('[{"@context":{}}]', JsonCanonicalizer::canonicalize($loader->load(self::URL)->document));
    }

    public function testWithDocumentRejectsMalformedJson(): void
    {
        $this->expectExceptionObject(
            new JsonLdError(JsonLdErrorCode::LoadingDocumentFailed, 'the text is not valid JSON (Syntax error)'),
        );

        (new BundledDocumentLoader())->withDocument(self::URL, '{"@context": ');
    }

    #[DataProvider('scalarDocuments')]
    public function testWithDocumentRejectsADocumentThatIsNotAnObjectOrArray(string $json): void
    {
        $this->expectExceptionObject(
            new JsonLdError(JsonLdErrorCode::InvalidRemoteContext, '"https://example.com/contexts/v1"'),
        );

        (new BundledDocumentLoader())->withDocument(self::URL, $json);
    }

    public function testWithDocumentRejectsAValueWhosePhpTypeIsNotAJsonType(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('A document may hold only JSON values; ArrayObject given');

        (new BundledDocumentLoader())->withDocument(self::URL, ['@context' => new ArrayObject()]);
    }

    public function testWithDocumentReadsAJsonSerializableAsWhatItSerializesTo(): void
    {
        $loader = (new BundledDocumentLoader())->withDocument(self::URL, new ExpandedDocument([new stdClass()]));

        $this->assertSame('[{}]', JsonCanonicalizer::canonicalize($loader->load(self::URL)->document));
    }

    public function testWithoutDocumentReturnsANewLoaderAndLeavesTheOriginalAlone(): void
    {
        $original = (new BundledDocumentLoader())
            ->withDocument(self::URL, ['@context' => ['a' => 'https://example.com/a']])
            ->withDocument('https://example.com/contexts/v2', ['@context' => ['b' => 'https://example.com/b']]);
        $reduced = $original->withoutDocument(self::URL);

        $this->assertNotSame($original, $reduced);
        $this->assertSame(self::URL, $original->load(self::URL)->documentUrl);
        $this->assertSame(
            '{"@context":{"b":"https://example.com/b"}}',
            JsonCanonicalizer::canonicalize($reduced->load('https://example.com/contexts/v2')->document),
        );

        $this->expectExceptionObject(
            new JsonLdError(JsonLdErrorCode::LoadingRemoteContextFailed, '"https://example.com/contexts/v1"'),
        );

        $reduced->load(self::URL);
    }

    public function testWithoutDocumentLetsThePinnedContextAnswerAgain(): void
    {
        $pinned = (new BundledDocumentLoader())->load('https://w3id.org/security/v1')->document;
        $loader = (new BundledDocumentLoader())
            ->withDocument('https://w3id.org/security/v1', ['@context' => []])
            ->withoutDocument('https://w3id.org/security/v1');

        $this->assertSame($pinned, $loader->load('https://w3id.org/security/v1')->document);
    }

    public function testWithoutDocumentForAUrlThatWasNotAddedChangesNothing(): void
    {
        $loader = (new BundledDocumentLoader())->withDocument(self::URL, ['@context' => []]);
        $same = $loader->withoutDocument('https://w3id.org/security/v1');

        $this->assertSame(self::URL, $same->load(self::URL)->documentUrl);
        $this->assertIsArray($same->load('https://w3id.org/security/v1')->document);
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

    public function testWithDocumentDoesNotApplyTheDocumentLimits(): void
    {
        $json = str_repeat('[', 200) . str_repeat(']', 200);
        $loader = (new BundledDocumentLoader())->withDocument(self::URL, $json);

        $this->assertSame($json, JsonCanonicalizer::canonicalize($loader->load(self::URL)->document));
    }
}
