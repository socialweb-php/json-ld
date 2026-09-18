<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\Context\ContextProcessor;
use SocialWeb\JsonLd\Context\DocumentLoader;
use SocialWeb\JsonLd\Context\LoadedDocument;
use SocialWeb\JsonLd\Context\TermDefinition;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Limits;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\ProcessingMode;
use SocialWeb\JsonLd\Rdf\JsonCanonicalizer;
use SocialWeb\Test\JsonLd\TestCase;

use function array_keys;
use function array_map;
use function json_decode;
use function str_repeat;
use function strval;

use const JSON_THROW_ON_ERROR;

class ContextProcessorTest extends TestCase
{
    private const string DOCUMENT = 'https://example.com/dir/doc.jsonld';

    public function testLeavesTheActiveContextItWasGivenUnchanged(): void
    {
        $active = ActiveContext::initial(self::DOCUMENT);
        $result = self::process('{"@vocab": "https://example.com/ns#", "name": "https://example.com/name"}', $active);

        $this->assertSame([], $active->termDefinitions);
        $this->assertNull($active->vocabularyMapping);
        $this->assertSame(['name'], array_keys($result->termDefinitions));
        $this->assertSame(self::DOCUMENT, $result->baseIri);
        $this->assertSame(self::DOCUMENT, $result->originalBaseUrl);
    }

    public function testAppliesTheContextsOfAnArrayInOrder(): void
    {
        $result = self::process('[{"a": "ex:one", "b": "ex:b"}, {"a": "ex:two"}]');

        $this->assertSame('ex:two', $result->termDefinition('a')?->iriMapping);
        $this->assertSame('ex:b', $result->termDefinition('b')?->iriMapping);
    }

    public function testAnEmptyArrayChangesNothing(): void
    {
        $active = self::process('{"a": "ex:a"}');

        $this->assertSame($active, self::process('[]', $active));
    }

    public function testNullResetsToTheOriginalBase(): void
    {
        $active = self::process('{"@base": "https://example.org/", "@vocab": "ex:", "@language": "en", "a": "ex:a"}');
        $result = self::process('null', $active);

        $this->assertSame([], $result->termDefinitions);
        $this->assertSame(self::DOCUMENT, $result->baseIri);
        $this->assertSame(self::DOCUMENT, $result->originalBaseUrl);
        $this->assertNull($result->vocabularyMapping);
        $this->assertNull($result->defaultLanguage);
        $this->assertNull($result->previousContext);
    }

    public function testNullMayNotDiscardProtectedTerms(): void
    {
        $active = self::process('{"@protected": true, "a": "ex:a"}');

        $this->assertError(ErrorCode::InvalidContextNullification, 'null', active: $active);
        $this->assertError(ErrorCode::InvalidContextNullification, '[{"@protected": true, "a": "ex:a"}, null]');
    }

    public function testNullMayDiscardProtectedTermsWhenOverriding(): void
    {
        $active = self::process('{"@protected": true, "a": "ex:a"}');
        $result = self::processor()->process($active, null, self::DOCUMENT, overrideProtected: true);

        $this->assertSame([], $result->termDefinitions);
    }

    public function testNullAfterUnprotectedTermsIsAllowed(): void
    {
        $this->assertSame(['b'], array_keys(self::process('[{"a": "ex:a"}, null, {"b": "ex:b"}]')->termDefinitions));
    }

    public function testANonPropagatedContextRemembersTheContextItReplaces(): void
    {
        $active = self::process('{"a": "ex:a"}');
        $viaEntry = self::process('{"@propagate": false, "b": "ex:b"}', $active);
        $viaArgument = self::processor()
            ->process($active, json_decode('{"b": "ex:b"}'), self::DOCUMENT, propagate: false);
        $propagated = self::process('{"@propagate": true, "b": "ex:b"}', $active);

        $this->assertSame($active, $viaEntry->previousContext);
        $this->assertSame($active, $viaArgument->previousContext);
        $this->assertNull($propagated->previousContext);
        $this->assertNull(self::process('{"b": "ex:b"}', $active)->previousContext);
    }

    public function testTheEntryOverridesThePropagateArgument(): void
    {
        $active = self::process('{"a": "ex:a"}');
        $result = self::processor()->process(
            $active,
            json_decode('{"@propagate": true, "b": "ex:b"}'),
            self::DOCUMENT,
            propagate: false,
        );

        $this->assertNull($result->previousContext);
    }

    public function testAnEarlierPreviousContextIsKept(): void
    {
        $first = self::process('{"a": "ex:a"}');
        $second = self::process('{"@propagate": false, "b": "ex:b"}', $first);
        $third = self::process('{"@propagate": false, "c": "ex:c"}', $second);

        $this->assertSame($first, $third->previousContext);
    }

    public function testNullInANonPropagatedContextRemembersWhatItReplaced(): void
    {
        $active = self::process('{"a": "ex:a"}');
        $result = self::processor()->process($active, null, self::DOCUMENT, propagate: false);

        $this->assertSame([], $result->termDefinitions);
        $this->assertSame(['a'], array_keys($result->previousContext->termDefinitions ?? []));
    }

    #[DataProvider('invalidLocalContexts')]
    public function testRejectsALocalContextThatIsNotAMapAStringOrNull(string $context): void
    {
        $this->assertError(ErrorCode::InvalidLocalContext, $context);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLocalContexts(): iterable
    {
        yield 'true' => ['true'];
        yield 'number' => ['42'];
        yield 'number in an array' => ['[{"a": "ex:a"}, 42]'];
        yield 'array in an array' => ['[[]]'];
    }

    public function testLoadsARemoteContextAndResolvesItAgainstTheBaseUrl(): void
    {
        $loader = (new BundledDocumentLoader())
            ->with('https://example.com/dir/terms.jsonld', '{"@context": {"a": "ex:a"}}');
        $result = self::process('"terms.jsonld"', loader: $loader);

        $this->assertSame(['a'], array_keys($result->termDefinitions));
    }

    public function testResolvesContextUrlsInsideARemoteContextAgainstItsOwnUrl(): void
    {
        $loader = (new BundledDocumentLoader())
            ->with('https://example.org/one/first', '{"@context": ["../two/second", {"a": "ex:a"}]}')
            ->with('https://example.org/two/second', '{"@context": {"b": "ex:b"}}');
        $result = self::process('"https://example.org/one/first"', loader: $loader);

        $this->assertSame(['b', 'a'], array_keys($result->termDefinitions));
    }

    public function testARemoteContextMayBeAnAbsoluteUrlWhenThereIsNoBaseUrl(): void
    {
        $processor = self::processor();
        $result = $processor->process(new ActiveContext(), 'https://w3id.org/security/multikey/v1', null);

        $this->assertSame(['Multikey', 'id', 'type'], array_keys($result->termDefinitions));
    }

    public function testARelativeContextUrlNeedsABaseUrl(): void
    {
        try {
            self::processor()->process(new ActiveContext(), 'terms.jsonld', null);
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $error) {
            $this->assertSame(ErrorCode::LoadingDocumentFailed, $error->errorCode);
            $this->assertSame('loading document failed: terms.jsonld', $error->getMessage());
        }
    }

    public function testIgnoresBaseInsideARemoteContext(): void
    {
        $loader = (new BundledDocumentLoader())
            ->with('https://example.org/context', '{"@context": {"@base": "https://example.org/base/"}}');

        $this->assertSame(self::DOCUMENT, self::process('"https://example.org/context"', loader: $loader)->baseIri);
    }

    public function testAsksTheLoaderOnlyOnceForEachUrl(): void
    {
        $loader = new class implements DocumentLoader {
            public int $calls = 0;

            public function load(string $url): LoadedDocument
            {
                $this->calls++;

                return new LoadedDocument($url, ['@context' => ['a' => 'ex:a']]);
            }
        };

        $processor = new ContextProcessor(new Options(documentLoader: $loader));
        $processor->process(new ActiveContext(), ['https://example.org/c', 'https://example.org/c'], null);
        $processor->process(new ActiveContext(), 'https://example.org/c', null);
        $processor->process(new ActiveContext(), json_decode('{"@import": "https://example.org/c"}'), null);

        $this->assertSame(1, $loader->calls);
    }

    public function testUsesTheDocumentUrlTheLoaderReports(): void
    {
        $loader = new class implements DocumentLoader {
            public function load(string $url): LoadedDocument
            {
                return match ($url) {
                    'https://example.org/moved' => new LoadedDocument(
                        'https://example.org/elsewhere/context',
                        ['@context' => ['next', ['a' => 'ex:a']]],
                    ),
                    'https://example.org/elsewhere/next' => new LoadedDocument($url, ['@context' => ['b' => 'ex:b']]),
                    default => throw new LogicException('Unexpected URL ' . $url),
                };
            }
        };

        $result = self::process('"https://example.org/moved"', loader: $loader);

        $this->assertSame(['b', 'a'], array_keys($result->termDefinitions));
    }

    public function testRemoteContextsAreNotSubjectToTheDocumentLimits(): void
    {
        $nested = str_repeat('{"a": {"@id": "ex:a", "@context": ', 10) . '{}' . str_repeat('}}', 10);
        $deep = '{"@context": ' . $nested . '}';
        $loader = (new BundledDocumentLoader())->with('https://example.org/deep', $deep);
        $processor = new ContextProcessor(
            new Options(limits: new Limits(maxDepth: 4, maxValues: 4), documentLoader: $loader),
        );

        $result = $processor->process(new ActiveContext(), 'https://example.org/deep', null);

        $this->assertSame(['a'], array_keys($result->termDefinitions));
    }

    public function testWrapsWhateverTheLoaderThrows(): void
    {
        $failure = new LogicException('The network is down');
        $loader = new class ($failure) implements DocumentLoader {
            public function __construct(private readonly LogicException $failure)
            {
            }

            public function load(string $url): LoadedDocument
            {
                throw $this->failure;
            }
        };

        try {
            self::process('"https://example.org/context"', loader: $loader);
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $error) {
            $this->assertSame(ErrorCode::LoadingRemoteContextFailed, $error->errorCode);
            $this->assertSame('loading remote context failed: https://example.org/context', $error->getMessage());
            $this->assertSame($failure, $error->getPrevious());
        }
    }

    public function testWrapsAnotherJsonLdErrorFromTheLoader(): void
    {
        $failure = new JsonLdError(ErrorCode::LoadingDocumentFailed, 'nope');
        $loader = new class ($failure) implements DocumentLoader {
            public function __construct(private readonly JsonLdError $failure)
            {
            }

            public function load(string $url): LoadedDocument
            {
                throw $this->failure;
            }
        };

        try {
            self::process('"https://example.org/context"', loader: $loader);
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $error) {
            $this->assertSame(ErrorCode::LoadingRemoteContextFailed, $error->errorCode);
            $this->assertSame($failure, $error->getPrevious());
        }
    }

    public function testPassesOnTheLoadersOwnFailureUnwrapped(): void
    {
        try {
            self::process('"https://example.org/unknown"');
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $error) {
            $this->assertSame(ErrorCode::LoadingRemoteContextFailed, $error->errorCode);
            $this->assertSame('loading remote context failed: https://example.org/unknown', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function testADocumentTheReaderRejectsIsALoadingFailure(): void
    {
        $loader = new class implements DocumentLoader {
            public function load(string $url): LoadedDocument
            {
                return new LoadedDocument($url, ['@context' => ['a' => "\xff"]]);
            }
        };

        $this->assertError(ErrorCode::LoadingRemoteContextFailed, '"https://example.org/context"', loader: $loader);
    }

    #[DataProvider('invalidRemoteContexts')]
    public function testRejectsARemoteDocumentWithoutAContext(string $document): void
    {
        $loader = (new BundledDocumentLoader())->with('https://example.org/context', $document);

        $this->assertError(ErrorCode::InvalidRemoteContext, '"https://example.org/context"', loader: $loader);
        $this->assertError(
            ErrorCode::InvalidRemoteContext,
            '{"@import": "https://example.org/context"}',
            loader: $loader,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRemoteContexts(): iterable
    {
        yield 'no @context entry' => ['{"a": "ex:a"}'];
        yield 'top-level array' => ['[{"@context": {}}]'];
    }

    public function testARemoteContextMayBeNull(): void
    {
        $loader = (new BundledDocumentLoader())->with('https://example.org/context', '{"@context": null}');
        $result = self::process('"https://example.org/context"', self::process('{"a": "ex:a"}'), loader: $loader);

        $this->assertSame([], $result->termDefinitions);
    }

    public function testStopsAChainOfRemoteContextsAtTheDepthLimit(): void
    {
        $loader = (new BundledDocumentLoader())
            ->with('https://example.org/1', '{"@context": ["https://example.org/2", {"one": "ex:1"}]}')
            ->with('https://example.org/2', '{"@context": ["https://example.org/3", {"two": "ex:2"}]}')
            ->with('https://example.org/3', '{"@context": {"three": "ex:3"}}');

        $allowed = new ContextProcessor(new Options(limits: new Limits(maxDepth: 3), documentLoader: $loader));
        $result = $allowed->process(new ActiveContext(), 'https://example.org/1', null);

        $this->assertSame(['three', 'two', 'one'], array_keys($result->termDefinitions));

        $refused = new ContextProcessor(new Options(limits: new Limits(maxDepth: 2), documentLoader: $loader));

        try {
            $refused->process(new ActiveContext(), 'https://example.org/1', null);
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $error) {
            $this->assertSame(ErrorCode::ContextOverflow, $error->errorCode);
            $this->assertSame('context overflow: https://example.org/3', $error->getMessage());
        }
    }

    public function testAContextThatIncludesItselfOverflows(): void
    {
        $loader = (new BundledDocumentLoader())
            ->with('https://example.org/loop', '{"@context": ["https://example.org/loop"]}');

        $this->assertError(ErrorCode::ContextOverflow, '"https://example.org/loop"', loader: $loader);
    }

    public function testSiblingRemoteContextsDoNotCountTowardTheDepth(): void
    {
        $loader = (new BundledDocumentLoader())
            ->with('https://example.org/1', '{"@context": {"one": "ex:1"}}')
            ->with('https://example.org/2', '{"@context": {"two": "ex:2"}}');
        $processor = new ContextProcessor(new Options(limits: new Limits(maxDepth: 1), documentLoader: $loader));

        $result = $processor->process(new ActiveContext(), ['https://example.org/1', 'https://example.org/2'], null);

        $this->assertSame(['one', 'two'], array_keys($result->termDefinitions));
    }

    public function testAScopedContextMayReferToTheRemoteContextThatDefinesIt(): void
    {
        $loader = (new BundledDocumentLoader())->with(
            'https://example.org/self',
            '{"@context": {"a": {"@id": "ex:a", "@context": "https://example.org/self"}}}',
        );
        $result = self::process('"https://example.org/self"', loader: $loader);

        $this->assertSame('https://example.org/self', $result->termDefinition('a')?->context);
        $this->assertSame('https://example.org/self', $result->termDefinition('a')->baseUrl);
    }

    public function testAScopedRemoteContextMayRedefineProtectedTerms(): void
    {
        $loader = (new BundledDocumentLoader())
            ->with('https://example.org/scoped', '{"@context": {"a": "ex:other"}}');
        $result = self::process(
            '{"@protected": true, "a": "ex:a", "b": {"@id": "ex:b", "@context": "https://example.org/scoped"}}',
            loader: $loader,
        );

        $this->assertSame('ex:a', $result->termDefinition('a')?->iriMapping);
    }

    public function testImportMergesTheImportedContextUnderTheImportingOne(): void
    {
        $loader = (new BundledDocumentLoader())->with(
            'https://example.com/dir/imported.jsonld',
            '{"@context": {"@vocab": "https://example.org/imported#", "a": "ex:imported", "b": "ex:b"}}',
        );
        $result = self::process('{"@import": "imported.jsonld", "a": "ex:importing", "c": "ex:c"}', loader: $loader);

        $this->assertSame('ex:importing', $result->termDefinition('a')?->iriMapping);
        $this->assertSame('ex:b', $result->termDefinition('b')?->iriMapping);
        $this->assertSame('ex:c', $result->termDefinition('c')?->iriMapping);
        $this->assertSame('https://example.org/imported#', $result->vocabularyMapping);
    }

    public function testImportLetsTheImportingContextProtectImportedTerms(): void
    {
        $loader = (new BundledDocumentLoader())
            ->with('https://example.org/imported', '{"@context": {"a": "ex:a"}}');
        $result = self::process('{"@import": "https://example.org/imported", "@protected": true}', loader: $loader);

        $this->assertTrue($result->termDefinition('a')?->protected);
    }

    /**
     * @return iterable<string, array{string, ErrorCode}>
     */
    public static function invalidImports(): iterable
    {
        yield 'not a string' => ['{"@import": {}}', ErrorCode::InvalidImportValue];
        yield 'relative, unknown' => ['{"@import": "unknown.jsonld"}', ErrorCode::LoadingRemoteContextFailed];
        yield 'imported context is an array' => [
            '{"@import": "https://example.org/array"}',
            ErrorCode::InvalidRemoteContext,
        ];
        yield 'imported context is a string' => [
            '{"@import": "https://example.org/string"}',
            ErrorCode::InvalidRemoteContext,
        ];
        yield 'imported context imports' => [
            '{"@import": "https://example.org/importing"}',
            ErrorCode::InvalidContextEntry,
        ];
    }

    #[DataProvider('invalidImports')]
    public function testRejectsAnInvalidImport(string $context, ErrorCode $expected): void
    {
        $loader = (new BundledDocumentLoader())
            ->with('https://example.org/array', '{"@context": [{"a": "ex:a"}]}')
            ->with('https://example.org/string', '{"@context": "https://example.org/array"}')
            ->with('https://example.org/importing', '{"@context": {"@import": "https://example.org/array"}}');

        $this->assertError($expected, $context, loader: $loader);
    }

    public function testAcceptsVersionOnePointOne(): void
    {
        $this->assertSame(['a'], array_keys(self::process('{"@version": 1.1, "a": "ex:a"}')->termDefinitions));
    }

    /**
     * @return iterable<string, array{string, ErrorCode, 2?: ProcessingMode}>
     */
    public static function invalidContextDefinitions(): iterable
    {
        yield '@version 1.0' => ['{"@version": 1.0}', ErrorCode::InvalidVersionValue];
        yield '@version as a string' => ['{"@version": "1.1"}', ErrorCode::InvalidVersionValue];
        yield '@version 1' => ['{"@version": 1}', ErrorCode::InvalidVersionValue];
        yield '@version null' => ['{"@version": null}', ErrorCode::InvalidVersionValue];
        yield '@version in 1.0 mode' => [
            '{"@version": 1.1}',
            ErrorCode::ProcessingModeConflict,
            ProcessingMode::JsonLd10,
        ];
        yield '@import in 1.0 mode' => [
            '{"@import": "https://w3id.org/security/v1"}',
            ErrorCode::InvalidContextEntry,
            ProcessingMode::JsonLd10,
        ];
        yield '@base is not a string' => ['{"@base": true}', ErrorCode::InvalidBaseIri];
        yield '@vocab is not a string' => ['{"@vocab": true}', ErrorCode::InvalidVocabMapping];
        yield '@vocab has the form of a keyword' => ['{"@vocab": "@ignoreMe"}', ErrorCode::InvalidVocabMapping];
        yield 'relative @vocab in 1.0 mode' => [
            '{"@vocab": "relative/"}',
            ErrorCode::InvalidVocabMapping,
            ProcessingMode::JsonLd10,
        ];
        yield '@language is not a string' => ['{"@language": true}', ErrorCode::InvalidDefaultLanguage];
        yield '@direction is not ltr or rtl' => ['{"@direction": "up"}', ErrorCode::InvalidBaseDirection];
        yield '@direction is not a string' => ['{"@direction": true}', ErrorCode::InvalidBaseDirection];
        yield '@direction in 1.0 mode' => [
            '{"@direction": "ltr"}',
            ErrorCode::InvalidContextEntry,
            ProcessingMode::JsonLd10,
        ];
        yield '@protected is a string' => [
            '{"@protected": "true", "a": "ex:a"}',
            ErrorCode::InvalidProtectedValue,
        ];
        yield '@protected is null' => ['{"@protected": null, "a": "ex:a"}', ErrorCode::InvalidProtectedValue];
        yield '@propagate is not a boolean' => ['{"@propagate": "no"}', ErrorCode::InvalidPropagateValue];
        yield '@propagate in 1.0 mode' => [
            '{"@propagate": true}',
            ErrorCode::InvalidContextEntry,
            ProcessingMode::JsonLd10,
        ];
    }

    #[DataProvider('invalidContextDefinitions')]
    public function testRejectsAnInvalidContextDefinition(
        string $context,
        ErrorCode $expected,
        ProcessingMode $mode = ProcessingMode::JsonLd11,
    ): void {
        $this->assertError($expected, $context, $mode);
    }

    public function testARelativeBaseNeedsABaseToResolveAgainst(): void
    {
        $this->assertError(ErrorCode::InvalidBaseIri, '{"@base": "relative/"}', active: new ActiveContext());
    }

    public function testSetsResolvesAndRemovesTheBaseIri(): void
    {
        $this->assertSame('https://example.org/', self::process('{"@base": "https://example.org/"}')->baseIri);
        $this->assertSame('https://example.com/dir/sub/', self::process('{"@base": "sub/"}')->baseIri);
        $this->assertSame(self::DOCUMENT, self::process('{"@base": ""}')->baseIri);
        $this->assertNull(self::process('{"@base": null}')->baseIri);
        $this->assertSame(self::DOCUMENT, self::process('{"@base": null}')->originalBaseUrl);
        $this->assertSame(
            'https://example.org/',
            self::process('{"@base": "https://example.org/"}', new ActiveContext())->baseIri,
        );
        $this->assertSame(
            'https://example.org/a/b',
            self::process('[{"@base": "https://example.org/a/"}, {"@base": "b"}]')->baseIri,
        );
    }

    public function testSetsExpandsAndRemovesTheVocabularyMapping(): void
    {
        $this->assertSame(
            'https://example.org/ns#',
            self::process('{"@vocab": "https://example.org/ns#"}')->vocabularyMapping,
        );
        $this->assertSame('_:', self::process('{"@vocab": "_:"}')->vocabularyMapping);
        $this->assertSame('https://example.com/dir/ns/', self::process('{"@vocab": "ns/"}')->vocabularyMapping);
        $this->assertSame(self::DOCUMENT, self::process('{"@vocab": ""}')->vocabularyMapping);
        $this->assertSame(
            'https://example.org/ns#sub/',
            self::process('[{"@vocab": "https://example.org/ns#"}, {"@vocab": "sub/"}]')->vocabularyMapping,
        );
        $this->assertSame(
            'https://example.org/ns#',
            self::process('[{"@vocab": "https://example.org/ns#"}, {"@vocab": ""}]')->vocabularyMapping,
        );
        $this->assertNull(
            self::process('[{"@vocab": "https://example.org/ns#"}, {"@vocab": null}]')->vocabularyMapping,
        );
        $this->assertSame(
            'https://example.org/ns#',
            self::process('{"@vocab": "https://example.org/ns#"}', mode: ProcessingMode::JsonLd10)->vocabularyMapping,
        );
        $this->assertSame('_:', self::process('{"@vocab": "_:"}', mode: ProcessingMode::JsonLd10)->vocabularyMapping);
    }

    public function testTheVocabularyMappingAppliesToTheTermsBesideIt(): void
    {
        $result = self::process('{"name": {"@type": "Text"}, "@vocab": "https://example.org/ns#"}');

        $this->assertSame('https://example.org/ns#name', $result->termDefinition('name')?->iriMapping);
        $this->assertSame('https://example.org/ns#Text', $result->termDefinition('name')->typeMapping);
    }

    public function testSetsAndRemovesTheDefaultLanguageAndDirection(): void
    {
        $set = self::process('{"@language": "en-US", "@direction": "rtl"}');

        $this->assertSame('en-US', $set->defaultLanguage);
        $this->assertSame('rtl', $set->defaultBaseDirection);
        $this->assertSame('ltr', self::process('{"@direction": "ltr"}', $set)->defaultBaseDirection);

        $removed = self::process('{"@language": null, "@direction": null}', $set);

        $this->assertNull($removed->defaultLanguage);
        $this->assertNull($removed->defaultBaseDirection);
        $this->assertSame('en', self::process('{"@language": "en"}', mode: ProcessingMode::JsonLd10)->defaultLanguage);
    }

    public function testDefinesASimpleTerm(): void
    {
        $definition = self::process('{"name": "https://example.org/name"}')->termDefinition('name');

        $this->assertEquals(new TermDefinition(iriMapping: 'https://example.org/name'), $definition);
    }

    public function testDefinesAnExpandedTermWithEveryPart(): void
    {
        $result = self::process(<<<'JSON'
            {
                "@vocab": "https://example.org/ns#",
                "label": {
                    "@id": "https://example.org/label",
                    "@container": ["@set", "@index"],
                    "@index": "key",
                    "@language": "EN",
                    "@direction": "rtl",
                    "@nest": "@nest",
                    "@prefix": false,
                    "@protected": true,
                    "@context": {"inner": "ex:inner"}
                }
            }
            JSON);

        $definition = $result->termDefinition('label');

        $this->assertNotNull($definition);
        $this->assertSame('https://example.org/label', $definition->iriMapping);
        $this->assertSame(['@index', '@set'], $definition->containerMapping);
        $this->assertSame('key', $definition->indexMapping);
        $this->assertTrue($definition->hasLanguageMapping);
        $this->assertSame('EN', $definition->languageMapping);
        $this->assertTrue($definition->hasDirectionMapping);
        $this->assertSame('rtl', $definition->directionMapping);
        $this->assertSame('@nest', $definition->nestValue);
        $this->assertFalse($definition->prefix);
        $this->assertTrue($definition->protected);
        $this->assertFalse($definition->reverse);
        $this->assertTrue($definition->hasContext);
        $this->assertSame('{"inner":"ex:inner"}', JsonCanonicalizer::canonicalize($definition->context));
        $this->assertSame(self::DOCUMENT, $definition->baseUrl);
        $this->assertNull($definition->typeMapping);
    }

    public function testATermWithoutAScopedContextHasNoBaseUrl(): void
    {
        $definition = self::process('{"a": {"@id": "ex:a"}}')->termDefinition('a');

        $this->assertFalse($definition?->hasContext);
        $this->assertNull($definition->baseUrl);
    }

    public function testKeepsAnExplicitNullLanguageDirectionAndScopedContext(): void
    {
        $definition = self::process('{"a": {"@id": "ex:a", "@language": null, "@direction": null, "@context": null}}')
            ->termDefinition('a');

        $this->assertNotNull($definition);
        $this->assertTrue($definition->hasLanguageMapping);
        $this->assertNull($definition->languageMapping);
        $this->assertTrue($definition->hasDirectionMapping);
        $this->assertNull($definition->directionMapping);
        $this->assertTrue($definition->hasContext);
        $this->assertNull($definition->context);
    }

    public function testIgnoresLanguageAndDirectionBesideAType(): void
    {
        $definition = self::process('{"a": {"@id": "ex:a", "@type": "@id", "@language": "en", "@direction": "ltr"}}')
            ->termDefinition('a');

        $this->assertNotNull($definition);
        $this->assertFalse($definition->hasLanguageMapping);
        $this->assertNull($definition->languageMapping);
        $this->assertFalse($definition->hasDirectionMapping);
        $this->assertNull($definition->directionMapping);
        $this->assertSame('@id', $definition->typeMapping);
    }

    public function testANullTermIsKeptWithoutAnIriMapping(): void
    {
        $result = self::process('{"@vocab": "ex:", "a": null, "b": {"@id": null}}');

        $this->assertEquals(new TermDefinition(), $result->termDefinition('a'));
        $this->assertEquals(new TermDefinition(), $result->termDefinition('b'));
    }

    public function testRedefiningATermReplacesEveryPartOfIt(): void
    {
        $result = self::process('[{"a": {"@id": "ex:a", "@container": "@list", "@type": "@id"}}, {"a": "ex:b"}]');

        $this->assertEquals(new TermDefinition(iriMapping: 'ex:b'), $result->termDefinition('a'));
    }

    /**
     * @return iterable<string, array{string, string, string | null}>
     */
    public static function iriMappings(): iterable
    {
        yield 'absolute IRI' => ['{"a": "https://example.org/a"}', 'a', 'https://example.org/a'];
        yield 'blank node identifier' => ['{"a": "_:a"}', 'a', '_:a'];
        yield 'keyword alias' => ['{"a": "@id"}', 'a', '@id'];
        yield 'alias of @type' => ['{"a": {"@id": "@type"}}', 'a', '@type'];
        yield 'compact IRI @id, prefix defined later' => [
            '{"a": "p:a", "p": "https://example.org/p#"}',
            'a',
            'https://example.org/p#a',
        ];
        yield 'another term as @id' => ['{"a": "b", "b": "https://example.org/b"}', 'a', 'https://example.org/b'];
        yield '@id relative to the vocabulary mapping' => [
            '{"@vocab": "ex:v#", "a": {"@id": "other"}}',
            'a',
            'ex:v#other',
        ];
        yield '@id equal to the term uses the vocabulary mapping' => [
            '{"@vocab": "ex:v#", "a": {"@id": "a"}}',
            'a',
            'ex:v#a',
        ];
        yield 'no @id uses the vocabulary mapping' => ['{"@vocab": "ex:v#", "a": {}}', 'a', 'ex:v#a'];
        yield 'compact IRI term, prefix in the same context' => [
            '{"p:a": {}, "p": "https://example.org/p#"}',
            'p:a',
            'https://example.org/p#a',
        ];
        yield 'compact IRI term, prefix in the active context' => [
            '[{"p": "https://example.org/p#"}, {"p:a": {}}]',
            'p:a',
            'https://example.org/p#a',
        ];
        yield 'compact IRI term, prefix not flagged as one' => [
            '{"p:a": {}, "p": {"@id": "https://example.org/p"}}',
            'p:a',
            'https://example.org/pa',
        ];
        yield 'compact IRI term, prefix defined as null' => ['{"p:a": {}, "p": null}', 'p:a', 'p:a'];
        yield 'IRI term without a definition for its scheme' => [
            '{"https://example.org/a": {}}',
            'https://example.org/a',
            'https://example.org/a',
        ];
        yield 'blank node term' => ['{"_:a": {}}', '_:a', '_:a'];
        yield 'compact IRI term equal to its own expansion' => [
            '{"p": "https://example.org/p#", "p:a": "https://example.org/p#a"}',
            'p:a',
            'https://example.org/p#a',
        ];
        yield 'IRI term mapped to itself' => [
            '{"https://example.org/a": "https://example.org/a"}',
            'https://example.org/a',
            'https://example.org/a',
        ];
        yield 'relative IRI term with a vocabulary mapping' => [
            '{"@vocab": "https://example.org/", "a/b": {}}',
            'a/b',
            'https://example.org/a/b',
        ];
        yield 'relative IRI term with an @id' => [
            '{"@vocab": "https://example.org/", "a/b": "a/b"}',
            'a/b',
            'https://example.org/a/b',
        ];
        yield 'term made of digits' => ['{"@vocab": "ex:v#", "123": {}}', '123', 'ex:v#123'];
        yield 'term ending in a colon' => ['{"@vocab": "ex:v#", "a:": {"@id": "ex:other"}}', 'a:', 'ex:other'];
        yield 'term that is only a colon after its first character' => [
            '{"@vocab": "ex:v#", ":a": {}}',
            ':a',
            'ex:v#:a',
        ];
        yield 'compact IRI term whose prefix is a colon' => [
            '{":": "https://example.org/", "::a": {}}',
            '::a',
            'https://example.org/a',
        ];
        yield 'term that names a reverse property defined before it' => [
            '{"children": {"@reverse": "ex:parent"}, "kids": "children"}',
            'kids',
            'ex:parent',
        ];
        yield 'term starting with a colon' => ['{"@vocab": "ex:v#", ":a": {"@id": "ex:other"}}', ':a', 'ex:other'];
    }

    #[DataProvider('iriMappings')]
    public function testMapsATermToAnIri(string $context, string $term, ?string $expected): void
    {
        $this->assertSame($expected, self::process($context)->termDefinition($term)?->iriMapping);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function prefixFlags(): iterable
    {
        yield 'simple term ending in a slash' => ['"https://example.org/"', true];
        yield 'simple term ending in a hash' => ['"https://example.org/ns#"', true];
        yield 'simple term ending in a colon' => ['"urn:example:"', true];
        yield 'simple term ending in a question mark' => ['"https://example.org/?"', true];
        yield 'simple term ending in an opening bracket' => ['"https://example.org/["', true];
        yield 'simple term ending in a closing bracket' => ['"https://example.org/]"', true];
        yield 'simple term ending in an at sign' => ['"https://example.org/@"', true];
        yield 'simple term that is a blank node identifier' => ['"_:p"', true];
        yield 'simple term ending in a letter' => ['"https://example.org/p"', false];
        yield 'simple term ending in an equals sign' => ['"https://example.org/?p="', false];
        yield 'simple term that is a keyword' => ['"@type"', false];
        yield 'expanded term ending in a slash' => ['{"@id": "https://example.org/"}', false];
        yield 'expanded term with @prefix true' => ['{"@id": "https://example.org/p", "@prefix": true}', true];
        yield 'expanded term with @prefix false' => ['{"@id": "https://example.org/", "@prefix": false}', false];
    }

    #[DataProvider('prefixFlags')]
    public function testFlagsATermAsAPrefix(string $definition, bool $expected): void
    {
        $this->assertSame($expected, self::process('{"p": ' . $definition . '}')->termDefinition('p')?->prefix);
    }

    public function testATermWithAColonOrSlashIsNeverAPrefix(): void
    {
        $result = self::process('{"@vocab": "https://example.org/", "a/": "https://example.org/a/", "x:": "urn:x:"}');

        $this->assertSame('https://example.org/a/', $result->termDefinition('a/')?->iriMapping);
        $this->assertFalse($result->termDefinition('a/')->prefix);
        $this->assertSame('urn:x:', $result->termDefinition('x:')?->iriMapping);
        $this->assertFalse($result->termDefinition('x:')->prefix);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typeMappings(): iterable
    {
        yield '@id' => ['"@id"', '@id'];
        yield '@vocab' => ['"@vocab"', '@vocab'];
        yield '@json' => ['"@json"', '@json'];
        yield '@none' => ['"@none"', '@none'];
        yield 'absolute IRI' => ['"https://example.org/T"', 'https://example.org/T'];
        yield 'compact IRI' => ['"xsd:date"', 'http://www.w3.org/2001/XMLSchema#date'];
        yield 'term' => ['"Date"', 'http://www.w3.org/2001/XMLSchema#date'];
        yield 'relative to the vocabulary mapping' => ['"Other"', 'https://example.org/ns#Other'];
    }

    #[DataProvider('typeMappings')]
    public function testMapsATermToAType(string $type, string $expected): void
    {
        $result = self::process(
            '{"@vocab": "https://example.org/ns#", "a": {"@id": "ex:a", "@type": ' . $type . '},'
            . ' "xsd": "http://www.w3.org/2001/XMLSchema#", "Date": "xsd:date"}',
        );

        $this->assertSame($expected, $result->termDefinition('a')?->typeMapping);
    }

    /**
     * @return iterable<string, array{string, list<string>, 2?: string | null}>
     */
    public static function containerMappings(): iterable
    {
        foreach (['@graph', '@id', '@index', '@language', '@list', '@set'] as $keyword) {
            yield $keyword => ['"' . $keyword . '"', [$keyword]];
            yield $keyword . ' in an array' => ['["' . $keyword . '"]', [$keyword]];
        }

        yield '@type' => ['"@type"', ['@type'], '@id'];
        yield '@type with @set' => ['["@type", "@set"]', ['@set', '@type'], '@id'];
        yield '@set with @index' => ['["@set", "@index"]', ['@index', '@set']];
        yield '@set with @id' => ['["@id", "@set"]', ['@id', '@set']];
        yield '@set with @language' => ['["@set", "@language"]', ['@language', '@set']];
        yield '@set with @graph' => ['["@set", "@graph"]', ['@graph', '@set']];
        yield '@graph with @id' => ['["@id", "@graph"]', ['@graph', '@id']];
        yield '@graph with @index' => ['["@graph", "@index"]', ['@graph', '@index']];
        yield '@graph with @id and @set' => ['["@set", "@id", "@graph"]', ['@graph', '@id', '@set']];
        yield '@graph with @index and @set' => ['["@index", "@graph", "@set"]', ['@graph', '@index', '@set']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('containerMappings')]
    public function testMapsATermToAContainer(string $container, array $expected, ?string $typeMapping = null): void
    {
        $definition = self::process('{"a": {"@id": "ex:a", "@container": ' . $container . '}}')->termDefinition('a');

        $this->assertSame($expected, $definition?->containerMapping);
        $this->assertSame($typeMapping, $definition->typeMapping);
    }

    public function testATypeContainerKeepsAVocabTypeMapping(): void
    {
        $definition = self::process('{"a": {"@id": "ex:a", "@container": "@type", "@type": "@vocab"}}')
            ->termDefinition('a');

        $this->assertSame('@vocab', $definition?->typeMapping);
    }

    public function testAllowsTheContainersOfJsonLd10InThatMode(): void
    {
        foreach (['@index', '@language', '@list', '@set'] as $keyword) {
            $definition = self::process(
                '{"a": {"@id": "ex:a", "@container": "' . $keyword . '"}}',
                mode: ProcessingMode::JsonLd10,
            )->termDefinition('a');

            $this->assertSame([$keyword], $definition?->containerMapping);
        }
    }

    public function testDefinesAReverseProperty(): void
    {
        $result = self::process(<<<'JSON'
            {
                "@vocab": "https://example.org/ns#",
                "children": {"@reverse": "parent", "@container": "@set", "@type": "@id", "@protected": true},
                "indexed": {"@reverse": "https://example.org/other", "@container": "@index"},
                "plain": {"@reverse": "_:b", "@container": null}
            }
            JSON);

        $this->assertEquals(
            new TermDefinition(
                iriMapping: 'https://example.org/ns#parent',
                protected: true,
                reverse: true,
                containerMapping: ['@set'],
                typeMapping: '@id',
            ),
            $result->termDefinition('children'),
        );
        $this->assertEquals(
            new TermDefinition(iriMapping: 'https://example.org/other', reverse: true, containerMapping: ['@index']),
            $result->termDefinition('indexed'),
        );
        $this->assertEquals(new TermDefinition(iriMapping: '_:b', reverse: true), $result->termDefinition('plain'));
    }

    public function testAReversePropertyMayDependOnATermDefinedLater(): void
    {
        $result = self::process('{"children": {"@reverse": "parent"}, "parent": "https://example.org/parent"}');

        $this->assertSame('https://example.org/parent', $result->termDefinition('children')?->iriMapping);
    }

    public function testRedefinesTypeOnlyToMakeItASetOrProtectIt(): void
    {
        $result = self::process('{"@type": {"@container": "@set", "@protected": true}}');

        $this->assertEquals(
            new TermDefinition(iriMapping: '@type', protected: true, containerMapping: ['@set']),
            $result->termDefinition('@type'),
        );
        $this->assertTrue(self::process('{"@type": {"@protected": true}}')->termDefinition('@type')?->protected);
        $this->assertSame(
            ['@set'],
            self::process('{"@type": {"@container": "@set"}}')->termDefinition('@type')?->containerMapping,
        );
    }

    public function testTheContextLevelProtectedFlagAppliesUnlessATermOptsOut(): void
    {
        $result = self::process('{"@protected": true, "a": "ex:a", "b": {"@id": "ex:b", "@protected": false}}');

        $this->assertTrue($result->termDefinition('a')?->protected);
        $this->assertFalse($result->termDefinition('b')?->protected);
        $this->assertFalse(self::process('{"@protected": false, "a": "ex:a"}')->termDefinition('a')?->protected);
    }

    public function testAProtectedTermMayBeRedefinedTheSameWay(): void
    {
        $active = self::process('{"@protected": true, "a": {"@id": "ex:a", "@type": "@id"}}');
        $result = self::process('{"a": {"@id": "ex:a", "@type": "@id"}}', $active);

        $this->assertSame($active->termDefinition('a'), $result->termDefinition('a'));
        $this->assertTrue($result->termDefinition('a')?->protected);
    }

    public function testAProtectedTermMayNotBeRedefinedDifferently(): void
    {
        $active = self::process('{"@protected": true, "a": {"@id": "ex:a", "@type": "@id"}}');

        $this->assertError(ErrorCode::ProtectedTermRedefinition, '{"a": "ex:a"}', active: $active);
        $this->assertError(ErrorCode::ProtectedTermRedefinition, '{"a": null}', active: $active);
        $this->assertError(ErrorCode::ProtectedTermRedefinition, '[{"@protected": true, "a": "ex:a"}, {"a": "ex:b"}]');
    }

    public function testAProtectedReversePropertyFollowsTheSameRule(): void
    {
        $active = self::process('{"@protected": true, "children": {"@reverse": "ex:parent"}}');
        $same = self::process('{"children": {"@reverse": "ex:parent"}}', $active);

        $this->assertSame($active->termDefinition('children'), $same->termDefinition('children'));
        $this->assertTrue($same->termDefinition('children')?->protected);
        $this->assertError(
            ErrorCode::ProtectedTermRedefinition,
            '{"children": {"@reverse": "ex:other"}}',
            active: $active,
        );
        $this->assertError(ErrorCode::ProtectedTermRedefinition, '{"children": "ex:parent"}', active: $active);

        $overridden = self::processor()->process(
            $active,
            json_decode('{"children": {"@reverse": "ex:other"}}'),
            self::DOCUMENT,
            overrideProtected: true,
        );

        $this->assertSame('ex:other', $overridden->termDefinition('children')?->iriMapping);
        $this->assertFalse($overridden->termDefinition('children')->protected);
    }

    public function testStrictModeRefusalsInAScopedContextAreNotReportedAsAnInvalidScopedContext(): void
    {
        try {
            self::process('{"a": {"@id": "ex:a", "@context": {"@ignoreMe": "ex:ignored"}}}');
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $loss) {
            $this->assertSame(DataLossCondition::ReservedTerm, $loss->condition);
            $this->assertSame('@ignoreMe', $loss->detail);
        }

        $lenient = self::process('{"a": {"@id": "ex:a", "@context": {"@ignoreMe": "ex:ignored"}}}', strict: false);

        $this->assertTrue($lenient->termDefinition('a')?->hasContext);
    }

    public function testAnUnprotectedTermMayBeRedefined(): void
    {
        $active = self::process('{"@protected": true, "a": "ex:a", "b": {"@id": "ex:b", "@protected": false}}');

        $this->assertSame('ex:other', self::process('{"b": "ex:other"}', $active)->termDefinition('b')?->iriMapping);
    }

    public function testAProtectedTermMayBeRedefinedWhenOverriding(): void
    {
        $active = self::process('{"@protected": true, "a": "ex:a"}');
        $result = self::processor()
            ->process($active, json_decode('{"a": "ex:b"}'), self::DOCUMENT, overrideProtected: true);

        $this->assertEquals(new TermDefinition(iriMapping: 'ex:b'), $result->termDefinition('a'));
    }

    public function testValidatesAScopedContextAgainstTheTermsAroundIt(): void
    {
        $result = self::process(
            '{"@protected": true, "p": "https://example.org/p#",'
            . ' "a": {"@id": "ex:a", "@context": {"p": "ex:q", "b": "p:b"}}}',
        );

        $this->assertSame('https://example.org/p#', $result->termDefinition('p')?->iriMapping);
        $this->assertNull($result->termDefinition('b'));
    }

    public function testReportsAnErrorInAScopedContextAsSuch(): void
    {
        try {
            self::process('{"a": {"@id": "ex:a", "@context": {"b": {"@id": "ex:b", "@container": "@bogus"}}}}');
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $error) {
            $this->assertSame(ErrorCode::InvalidScopedContext, $error->errorCode);
            $this->assertSame('invalid scoped context: a', $error->getMessage());
            $this->assertInstanceOf(JsonLdError::class, $error->getPrevious());
            $this->assertSame(ErrorCode::InvalidContainerMapping, $error->getPrevious()->errorCode);
        }
    }

    public function testAScopedContextThatCannotBeLoadedIsInvalid(): void
    {
        $this->assertError(
            ErrorCode::InvalidScopedContext,
            '{"a": {"@id": "ex:a", "@context": "https://example.org/unknown"}}',
        );
    }

    /**
     * @return iterable<string, array{string, ErrorCode, 2?: ProcessingMode}>
     */
    public static function invalidTermDefinitions(): iterable
    {
        $v10 = ProcessingMode::JsonLd10;

        yield 'cycle between two terms' => ['{"a": "b:x", "b": "a:y"}', ErrorCode::CyclicIriMapping];
        yield 'term that is its own @id prefix' => ['{"a": "a:x"}', ErrorCode::CyclicIriMapping];
        yield 'empty term' => ['{"": "ex:empty"}', ErrorCode::InvalidTermDefinition];
        yield 'true' => ['{"a": true}', ErrorCode::InvalidTermDefinition];
        yield 'number' => ['{"a": 1}', ErrorCode::InvalidTermDefinition];
        yield 'array' => ['{"a": ["ex:a"]}', ErrorCode::InvalidTermDefinition];
        yield 'unknown entry' => ['{"a": {"@id": "ex:a", "@bogus": true}}', ErrorCode::InvalidTermDefinition];
        yield 'unknown entry that is not a keyword' => [
            '{"a": {"@id": "ex:a", "id": "x"}}',
            ErrorCode::InvalidTermDefinition,
        ];

        yield 'keyword as a term' => ['{"@id": "ex:id"}', ErrorCode::KeywordRedefinition];
        yield 'keyword as a term, null' => ['{"@value": null}', ErrorCode::KeywordRedefinition];
        yield '@type as a string' => ['{"@type": "ex:type"}', ErrorCode::KeywordRedefinition];
        yield '@type as an empty map' => ['{"@type": {}}', ErrorCode::KeywordRedefinition];
        yield '@type with @id' => ['{"@type": {"@id": "@type", "@container": "@set"}}', ErrorCode::KeywordRedefinition];
        yield '@type with a list container' => ['{"@type": {"@container": "@list"}}', ErrorCode::KeywordRedefinition];
        yield '@type with a set container in an array' => [
            '{"@type": {"@container": ["@set"]}}',
            ErrorCode::KeywordRedefinition,
        ];
        yield '@type in 1.0 mode' => ['{"@type": {"@container": "@set"}}', ErrorCode::KeywordRedefinition, $v10];

        yield '@protected is not a boolean' => [
            '{"a": {"@id": "ex:a", "@protected": "yes"}}',
            ErrorCode::InvalidProtectedValue,
        ];
        yield '@protected in 1.0 mode' => [
            '{"a": {"@id": "ex:a", "@protected": true}}',
            ErrorCode::InvalidTermDefinition,
            $v10,
        ];

        yield '@type is not a string' => ['{"a": {"@id": "ex:a", "@type": true}}', ErrorCode::InvalidTypeMapping];
        yield '@type is a blank node' => ['{"a": {"@id": "ex:a", "@type": "_:t"}}', ErrorCode::InvalidTypeMapping];
        yield '@type is relative' => ['{"a": {"@id": "ex:a", "@type": "relative"}}', ErrorCode::InvalidTypeMapping];
        yield '@type is another keyword' => ['{"a": {"@id": "ex:a", "@type": "@list"}}', ErrorCode::InvalidTypeMapping];
        yield '@type has the form of a keyword' => [
            '{"a": {"@id": "ex:a", "@type": "@ignoreMe"}}',
            ErrorCode::InvalidTypeMapping,
        ];
        yield '@type @json in 1.0 mode' => [
            '{"a": {"@id": "ex:a", "@type": "@json"}}',
            ErrorCode::InvalidTypeMapping,
            $v10,
        ];
        yield '@type @none in 1.0 mode' => [
            '{"a": {"@id": "ex:a", "@type": "@none"}}',
            ErrorCode::InvalidTypeMapping,
            $v10,
        ];
        yield 'type container with an IRI type' => [
            '{"a": {"@id": "ex:a", "@container": "@type", "@type": "ex:T"}}',
            ErrorCode::InvalidTypeMapping,
        ];
        yield 'type container with @json' => [
            '{"a": {"@id": "ex:a", "@container": "@type", "@type": "@json"}}',
            ErrorCode::InvalidTypeMapping,
        ];

        yield '@reverse with @id' => ['{"a": {"@reverse": "ex:b", "@id": "ex:a"}}', ErrorCode::InvalidReverseProperty];
        yield '@reverse with @nest' => [
            '{"a": {"@reverse": "ex:b", "@nest": "@nest"}}',
            ErrorCode::InvalidReverseProperty,
        ];
        yield '@reverse with a list container' => [
            '{"a": {"@reverse": "ex:b", "@container": "@list"}}',
            ErrorCode::InvalidReverseProperty,
        ];
        yield '@reverse with an array container' => [
            '{"a": {"@reverse": "ex:b", "@container": ["@set"]}}',
            ErrorCode::InvalidReverseProperty,
        ];
        yield '@reverse with an unknown entry' => [
            '{"a": {"@reverse": "ex:b", "@bogus": true}}',
            ErrorCode::InvalidTermDefinition,
        ];
        yield '@reverse is not a string' => ['{"a": {"@reverse": true}}', ErrorCode::InvalidIriMapping];
        yield '@reverse is relative' => ['{"a": {"@reverse": "relative"}}', ErrorCode::InvalidIriMapping];

        yield '@id is not a string' => ['{"a": {"@id": true}}', ErrorCode::InvalidIriMapping];
        yield '@id is relative' => ['{"a": {"@id": "relative"}}', ErrorCode::InvalidIriMapping];
        yield '@id is a term defined as null' => ['{"a": "b", "b": null}', ErrorCode::InvalidIriMapping];
        yield '@id is @context' => ['{"a": "@context"}', ErrorCode::InvalidKeywordAlias];
        yield 'compact IRI term that expands elsewhere' => [
            '{"p": "https://example.org/p#", "p:a": "https://example.org/other"}',
            ErrorCode::InvalidIriMapping,
        ];
        yield 'IRI term mapped elsewhere' => [
            '{"https://example.org/a": "https://example.org/b"}',
            ErrorCode::InvalidIriMapping,
        ];
        yield 'relative IRI term mapped elsewhere' => [
            '{"@vocab": "https://example.org/", "a/b": "https://example.org/c"}',
            ErrorCode::InvalidIriMapping,
        ];
        yield 'relative IRI term without a vocabulary mapping' => ['{"a/b": {}}', ErrorCode::InvalidIriMapping];
        yield 'relative IRI term with a relative vocabulary mapping' => [
            '[{"@base": null}, {"@vocab": "v/", "a/b": {}}]',
            ErrorCode::InvalidIriMapping,
        ];
        yield 'plain term without a vocabulary mapping' => ['{"a": {}}', ErrorCode::InvalidIriMapping];
        yield 'plain term with @id equal to itself' => ['{"a": "a"}', ErrorCode::InvalidIriMapping];

        yield 'container is not a keyword' => [
            '{"a": {"@id": "ex:a", "@container": "@bogus"}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'container is a keyword that is not a container' => [
            '{"a": {"@id": "ex:a", "@container": "@value"}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'container is null' => ['{"a": {"@id": "ex:a", "@container": null}}', ErrorCode::InvalidContainerMapping];
        yield 'container is a number' => [
            '{"a": {"@id": "ex:a", "@container": 1}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'container is an empty array' => [
            '{"a": {"@id": "ex:a", "@container": []}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'container holds a number' => [
            '{"a": {"@id": "ex:a", "@container": ["@set", 1]}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'container repeats a keyword' => [
            '{"a": {"@id": "ex:a", "@container": ["@set", "@set"]}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'list with set' => [
            '{"a": {"@id": "ex:a", "@container": ["@list", "@set"]}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'index with id' => [
            '{"a": {"@id": "ex:a", "@container": ["@index", "@id"]}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'index with id and set' => [
            '{"a": {"@id": "ex:a", "@container": ["@index", "@id", "@set"]}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'graph with id and index' => [
            '{"a": {"@id": "ex:a", "@container": ["@graph", "@id", "@index"]}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'graph with language' => [
            '{"a": {"@id": "ex:a", "@container": ["@graph", "@language"]}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'graph with type' => [
            '{"a": {"@id": "ex:a", "@container": ["@graph", "@type"]}}',
            ErrorCode::InvalidContainerMapping,
        ];
        yield 'graph container in 1.0 mode' => [
            '{"a": {"@id": "ex:a", "@container": "@graph"}}',
            ErrorCode::InvalidContainerMapping,
            $v10,
        ];
        yield 'id container in 1.0 mode' => [
            '{"a": {"@id": "ex:a", "@container": "@id"}}',
            ErrorCode::InvalidContainerMapping,
            $v10,
        ];
        yield 'type container in 1.0 mode' => [
            '{"a": {"@id": "ex:a", "@container": "@type"}}',
            ErrorCode::InvalidContainerMapping,
            $v10,
        ];
        yield 'array container in 1.0 mode' => [
            '{"a": {"@id": "ex:a", "@container": ["@set"]}}',
            ErrorCode::InvalidContainerMapping,
            $v10,
        ];

        yield '@index without an index container' => [
            '{"@vocab": "ex:", "a": {"@container": "@set", "@index": "p"}}',
            ErrorCode::InvalidTermDefinition,
        ];
        yield '@index without a container' => [
            '{"@vocab": "ex:", "a": {"@index": "p"}}',
            ErrorCode::InvalidTermDefinition,
        ];
        yield '@index is not a string' => [
            '{"@vocab": "ex:", "a": {"@container": "@index", "@index": true}}',
            ErrorCode::InvalidTermDefinition,
        ];
        yield '@index is a keyword' => [
            '{"@vocab": "ex:", "a": {"@container": "@index", "@index": "@index"}}',
            ErrorCode::InvalidTermDefinition,
        ];
        yield '@index has the form of a keyword' => [
            '{"@vocab": "ex:", "a": {"@container": "@index", "@index": "@ignoreMe"}}',
            ErrorCode::InvalidTermDefinition,
        ];
        yield '@index does not expand to an IRI' => [
            '{"a": {"@id": "ex:a", "@container": "@index", "@index": "p"}}',
            ErrorCode::InvalidTermDefinition,
        ];
        yield '@index in 1.0 mode' => [
            '{"@vocab": "ex:", "a": {"@container": "@index", "@index": "p"}}',
            ErrorCode::InvalidTermDefinition,
            $v10,
        ];

        yield '@context in 1.0 mode' => [
            '{"a": {"@id": "ex:a", "@context": {}}}',
            ErrorCode::InvalidTermDefinition,
            $v10,
        ];
        yield '@language is not a string' => [
            '{"a": {"@id": "ex:a", "@language": true}}',
            ErrorCode::InvalidLanguageMapping,
        ];
        yield '@direction is not ltr or rtl' => [
            '{"a": {"@id": "ex:a", "@direction": "up"}}',
            ErrorCode::InvalidBaseDirection,
        ];
        yield '@direction is not a string' => [
            '{"a": {"@id": "ex:a", "@direction": true}}',
            ErrorCode::InvalidBaseDirection,
        ];

        yield '@nest is not a string' => ['{"a": {"@id": "ex:a", "@nest": true}}', ErrorCode::InvalidNestValue];
        yield '@nest is another keyword' => ['{"a": {"@id": "ex:a", "@nest": "@id"}}', ErrorCode::InvalidNestValue];
        yield '@nest in 1.0 mode' => [
            '{"a": {"@id": "ex:a", "@nest": "@nest"}}',
            ErrorCode::InvalidTermDefinition,
            $v10,
        ];

        yield '@prefix is not a boolean' => ['{"a": {"@id": "ex:a", "@prefix": "yes"}}', ErrorCode::InvalidPrefixValue];
        yield '@prefix on a term with a colon' => [
            '{"x:a": {"@id": "x:a", "@prefix": false}}',
            ErrorCode::InvalidTermDefinition,
        ];
        yield '@prefix on a term with a slash' => [
            '{"@vocab": "https://example.org/", "a/b": {"@prefix": false}}',
            ErrorCode::InvalidTermDefinition,
        ];
        yield '@prefix on a keyword alias' => [
            '{"a": {"@id": "@type", "@prefix": true}}',
            ErrorCode::InvalidTermDefinition,
        ];
        yield '@prefix in 1.0 mode' => [
            '{"a": {"@id": "ex:a", "@prefix": false}}',
            ErrorCode::InvalidTermDefinition,
            $v10,
        ];
    }

    #[DataProvider('invalidTermDefinitions')]
    public function testRejectsAnInvalidTermDefinition(
        string $context,
        ErrorCode $expected,
        ProcessingMode $mode = ProcessingMode::JsonLd11,
    ): void {
        $this->assertError($expected, $context, $mode);
    }

    public function testAllowsNestToNameATermAndAKeywordAliasWithoutPrefix(): void
    {
        $result = self::process('{"a": {"@id": "ex:a", "@nest": "wrapper"}, "t": {"@id": "@type", "@prefix": false}}');

        $this->assertSame('wrapper', $result->termDefinition('a')?->nestValue);
        $this->assertSame('@type', $result->termDefinition('t')?->iriMapping);
    }

    public function testNamesTheTermInTheError(): void
    {
        try {
            self::process('{"good": "ex:good", "bad": {"@id": true}}');
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $error) {
            $this->assertSame('invalid IRI mapping: bad', $error->getMessage());
        }
    }

    public function testVisitsTermsInCodePointOrder(): void
    {
        try {
            self::process('{"b": {"@id": true}, "a": true, "B": {"@type": true}}');
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $error) {
            $this->assertSame('invalid type mapping: B', $error->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reservedTerms(): iterable
    {
        yield 'term has the form of a keyword' => ['{"@ignoreMe": "ex:ignored", "kept": "ex:kept"}', '@ignoreMe'];
        yield '@id has the form of a keyword' => ['{"ignored": "@ignoreMe", "kept": "ex:kept"}', 'ignored'];
        yield '@reverse has the form of a keyword' => [
            '{"ignored": {"@reverse": "@ignoreMe"}, "kept": "ex:kept"}',
            'ignored',
        ];
        yield '@reverse is a keyword' => ['{"ignored": {"@reverse": "@type"}, "kept": "ex:kept"}', 'ignored'];
    }

    #[DataProvider('reservedTerms')]
    public function testStrictModeRefusesToIgnoreAReservedTerm(string $context, string $term): void
    {
        try {
            self::process($context);
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $loss) {
            $this->assertSame(DataLossCondition::ReservedTerm, $loss->condition);
            $this->assertSame($term, $loss->detail);
        }
    }

    #[DataProvider('reservedTerms')]
    public function testLenientModeIgnoresAReservedTerm(string $context, string $term): void
    {
        $result = self::process($context, strict: false);

        $this->assertSame(['kept'], array_map(strval(...), array_keys($result->termDefinitions)));
    }

    #[DataProvider('reservedRedefinitions')]
    public function testIgnoringAReservedTermKeepsAnEarlierDefinition(string $redefinition): void
    {
        $active = self::process('{"@protected": true, "a": "ex:a", "b": {"@id": "ex:b", "@protected": false}}');
        $result = self::process(
            '{"@vocab": "https://example.org/other#", "a": ' . $redefinition . ', "b": ' . $redefinition . '}',
            $active,
            strict: false,
        );

        $this->assertSame($active->termDefinition('a'), $result->termDefinition('a'));
        $this->assertSame($active->termDefinition('b'), $result->termDefinition('b'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedRedefinitions(): iterable
    {
        yield '@id has the form of a keyword' => ['{"@id": "@ignoreMe"}'];
        yield '@id as a string has the form of a keyword' => ['"@ignoreMe"'];
        yield '@reverse has the form of a keyword' => ['{"@reverse": "@ignoreMe"}'];
    }

    public function testProcessesTheContextOfTheSpecificationsFirstExample(): void
    {
        $result = self::process(<<<'JSON'
            {
                "name": "http://schema.org/name",
                "image": {"@id": "http://schema.org/image", "@type": "@id"},
                "homepage": {"@id": "http://schema.org/url", "@type": "@id"}
            }
            JSON);

        $this->assertEquals(
            [
                'homepage' => new TermDefinition(iriMapping: 'http://schema.org/url', typeMapping: '@id'),
                'image' => new TermDefinition(iriMapping: 'http://schema.org/image', typeMapping: '@id'),
                'name' => new TermDefinition(iriMapping: 'http://schema.org/name'),
            ],
            $result->termDefinitions,
        );
    }

    private static function processor(
        ProcessingMode $mode = ProcessingMode::JsonLd11,
        bool $strict = true,
        ?DocumentLoader $loader = null,
    ): ContextProcessor {
        return new ContextProcessor(
            new Options(processingMode: $mode, strict: $strict, documentLoader: $loader ?? new BundledDocumentLoader()),
        );
    }

    private static function process(
        string $context,
        ?ActiveContext $active = null,
        ProcessingMode $mode = ProcessingMode::JsonLd11,
        bool $strict = true,
        ?DocumentLoader $loader = null,
    ): ActiveContext {
        return self::processor($mode, $strict, $loader)->process(
            $active ?? ActiveContext::initial(self::DOCUMENT),
            json_decode($context, flags: JSON_THROW_ON_ERROR),
            self::DOCUMENT,
        );
    }

    private function assertError(
        ErrorCode $expected,
        string $context,
        ProcessingMode $mode = ProcessingMode::JsonLd11,
        ?ActiveContext $active = null,
        ?DocumentLoader $loader = null,
    ): void {
        try {
            self::process($context, $active, $mode, loader: $loader);
        } catch (JsonLdError $error) {
            $this->assertSame($expected, $error->errorCode, $error->getMessage());

            return;
        }

        $this->fail('Expected a JsonLdError with the code "' . $expected->value . '"');
    }
}
