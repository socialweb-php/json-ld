<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\IriExpander;
use SocialWeb\JsonLd\Context\TermDefinition;
use SocialWeb\Test\JsonLd\TestCase;

class IriExpanderTest extends TestCase
{
    #[DataProvider('expansions')]
    public function testExpands(?string $value, bool $documentRelative, bool $vocab, ?string $expected): void
    {
        $this->assertSame($expected, IriExpander::expand(self::context(), $value, $documentRelative, $vocab));
    }

    /**
     * @return iterable<string, array{string | null, bool, bool, string | null}>
     */
    public static function expansions(): iterable
    {
        yield 'null' => [null, true, true, null];
        yield 'a keyword' => ['@type', false, false, '@type'];
        yield 'the form of a keyword' => ['@ignoreMe', true, true, null];
        yield 'an alias of a keyword, without vocab' => ['id', false, false, '@id'];
        yield 'an alias of a keyword, with vocab' => ['id', false, true, '@id'];
        yield 'a term, with vocab' => ['name', false, true, 'https://example.com/ns#name'];
        yield 'a term, without vocab, is a relative reference' => ['name', true, false, 'https://example.com/dir/name'];
        yield 'a term, with neither flag' => ['name', false, false, 'name'];
        yield 'a term defined as null, with vocab' => ['hidden', false, true, null];
        yield 'a term defined as null, without vocab' => ['hidden', true, false, 'https://example.com/dir/hidden'];
        yield 'a blank node identifier' => ['_:b0', true, true, '_:b0'];
        yield 'an IRI with an authority' => ['ex://host/path', true, true, 'ex://host/path'];
        yield 'a compact IRI' => ['ex:thing', false, false, 'https://example.com/ns#thing'];
        yield 'a compact IRI with an empty suffix' => ['ex:', false, false, 'https://example.com/ns#'];
        yield 'a compact IRI whose prefix is not flagged' => ['name:x', true, true, 'name:x'];
        yield 'a compact IRI whose prefix is defined as null' => ['hidden:x', true, true, 'hidden:x'];
        yield 'an absolute IRI with an unknown scheme' => ['urn:isbn:123', true, true, 'urn:isbn:123'];
        yield 'a colon first is not a prefix separator' => [':x', false, true, 'https://example.com/vocab#:x'];
        yield 'a colon after an invalid scheme' => ['1a:x', false, true, 'https://example.com/vocab#1a:x'];
        yield 'an undefined term, with vocab' => ['other', true, true, 'https://example.com/vocab#other'];
        yield 'an undefined term, document relative' => ['other', true, false, 'https://example.com/dir/other'];
        yield 'dot segments, document relative' => ['../up', true, false, 'https://example.com/up'];
        yield 'the empty string, document relative' => ['', true, false, 'https://example.com/dir/doc'];
        yield 'the empty string, with vocab' => ['', false, true, 'https://example.com/vocab#'];
        yield 'a single character' => ['a', false, false, 'a'];
    }

    public function testBothFlagsAreOffUnlessAsked(): void
    {
        $this->assertSame('name', IriExpander::expand(self::context(), 'name'));
        $this->assertSame('other', IriExpander::expand(self::context(), 'other'));
    }

    public function testLeavesARelativeReferenceAloneWithoutABaseOrAVocabularyMapping(): void
    {
        $context = new ActiveContext();

        $this->assertSame('other', IriExpander::expand($context, 'other', documentRelative: true, vocab: true));
    }

    public function testPrefersTheVocabularyMappingToTheBase(): void
    {
        $context = ActiveContext::initial('https://example.com/doc')->withVocabularyMapping('https://example.com/v#');

        $this->assertSame(
            'https://example.com/v#other',
            IriExpander::expand($context, 'other', documentRelative: true, vocab: true),
        );
    }

    public function testAsksForTheValueAndThenItsPrefixToBeDefined(): void
    {
        $asked = [];
        $defined = self::context()->withTermDefinition(
            'late',
            new TermDefinition(iriMapping: 'https://example.com/late#', prefix: true),
        );
        $define = static function (string $term) use (&$asked, $defined): ActiveContext {
            $asked[] = $term;

            return $defined;
        };

        $this->assertSame(
            'https://example.com/late#x',
            IriExpander::expand(new ActiveContext(), 'late:x', define: $define),
        );
        $this->assertSame(['late:x', 'late'], $asked);
    }

    public function testUsesADefinitionMadeForTheValueItself(): void
    {
        $define = static fn (string $term): ActiveContext => self::context();

        $this->assertSame(
            'https://example.com/ns#name',
            IriExpander::expand(new ActiveContext(), 'name', vocab: true, define: $define),
        );
    }

    public function testDoesNotAskForAKeywordOrABlankNodePrefixToBeDefined(): void
    {
        $asked = [];
        $define = static function (string $term) use (&$asked): ActiveContext {
            $asked[] = $term;

            return new ActiveContext();
        };

        IriExpander::expand(new ActiveContext(), '@id', define: $define);
        IriExpander::expand(new ActiveContext(), '@ignoreMe', define: $define);
        IriExpander::expand(new ActiveContext(), null, define: $define);
        IriExpander::expand(new ActiveContext(), '_:b0', define: $define);
        IriExpander::expand(new ActiveContext(), 'http://example.com/', define: $define);

        $this->assertSame(['_:b0', 'http://example.com/'], $asked);
    }

    private static function context(): ActiveContext
    {
        return ActiveContext::initial('https://example.com/dir/doc')
            ->withVocabularyMapping('https://example.com/vocab#')
            ->withTermDefinition('id', new TermDefinition(iriMapping: '@id'))
            ->withTermDefinition('name', new TermDefinition(iriMapping: 'https://example.com/ns#name'))
            ->withTermDefinition('hidden', new TermDefinition(iriMapping: null, prefix: true))
            ->withTermDefinition('ex', new TermDefinition(iriMapping: 'https://example.com/ns#', prefix: true));
    }
}
