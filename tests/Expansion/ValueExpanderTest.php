<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Expansion;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\TermDefinition;
use SocialWeb\JsonLd\Expansion\ValueExpander;
use SocialWeb\Test\JsonLd\TestCase;

class ValueExpanderTest extends TestCase
{
    /**
     * The entries of a value object are in code point order.
     *
     * @param array<string, mixed> $expected
     */
    #[DataProvider('values')]
    public function testExpands(array $expected, string $activeProperty, mixed $value): void
    {
        $this->assertSame($expected, (array) ValueExpander::expand(self::context(), $activeProperty, $value));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, mixed}>
     */
    public static function values(): iterable
    {
        yield 'a string under a term whose values are IRIs' => [
            ['@id' => 'https://example.com/dir/other'],
            'link',
            'other',
        ];
        yield 'a term is not looked up under @id' => [['@id' => 'https://example.com/dir/plain'], 'link', 'plain'];
        yield 'a number under a term whose values are IRIs' => [['@value' => 5], 'link', 5];
        yield 'a string under a term whose values are vocabulary terms' => [
            ['@id' => 'https://example.com/ns#plain'],
            'kind',
            'plain',
        ];
        yield 'an unknown string under a term whose values are vocabulary terms' => [
            ['@id' => 'https://example.com/vocab#other'],
            'kind',
            'other',
        ];
        yield 'a boolean under a term whose values are vocabulary terms' => [['@value' => true], 'kind', true];
        yield 'a string under a term with a datatype' => [
            ['@type' => 'http://www.w3.org/2001/XMLSchema#date', '@value' => '2026-01-01'],
            'date',
            '2026-01-01',
        ];
        yield 'a number under a term with a datatype' => [
            ['@type' => 'http://www.w3.org/2001/XMLSchema#date', '@value' => 1.5],
            'date',
            1.5,
        ];
        yield 'a string under a term whose type is @none is treated as under a term with no type' => [
            ['@direction' => 'rtl', '@language' => 'en-us', '@value' => 'text'],
            'untyped',
            'text',
        ];
        yield 'a number under a term whose type is @none' => [['@value' => 5], 'untyped', 5];
        yield 'a string takes the default language and direction' => [
            ['@direction' => 'rtl', '@language' => 'en-us', '@value' => 'text'],
            'plain',
            'text',
        ];
        yield 'a string under a property with no term' => [
            ['@direction' => 'rtl', '@language' => 'en-us', '@value' => 'text'],
            'https://example.com/unknown',
            'text',
        ];
        yield 'a number takes neither' => [['@value' => 5], 'plain', 5];
        yield 'the language and direction of the term come first' => [
            ['@direction' => 'ltr', '@language' => 'de-at', '@value' => 'text'],
            'german',
            'text',
        ];
        yield 'a term with a language and direction of null has neither' => [['@value' => 'text'], 'bare', 'text'];
    }

    private static function context(): ActiveContext
    {
        return ActiveContext::initial('https://example.com/dir/doc')
            ->withVocabularyMapping('https://example.com/vocab#')
            ->withDefaultLanguage('en-US')
            ->withDefaultBaseDirection('rtl')
            ->withTermDefinitions([
                'plain' => new TermDefinition(iriMapping: 'https://example.com/ns#plain'),
                'link' => new TermDefinition(iriMapping: 'https://example.com/ns#link', typeMapping: '@id'),
                'kind' => new TermDefinition(iriMapping: 'https://example.com/ns#kind', typeMapping: '@vocab'),
                'untyped' => new TermDefinition(iriMapping: 'https://example.com/ns#untyped', typeMapping: '@none'),
                'date' => new TermDefinition(
                    iriMapping: 'https://example.com/ns#date',
                    typeMapping: 'http://www.w3.org/2001/XMLSchema#date',
                ),
                'german' => new TermDefinition(
                    iriMapping: 'https://example.com/ns#german',
                    hasDirectionMapping: true,
                    directionMapping: 'ltr',
                    hasLanguageMapping: true,
                    languageMapping: 'de-AT',
                ),
                'bare' => new TermDefinition(
                    iriMapping: 'https://example.com/ns#bare',
                    hasDirectionMapping: true,
                    hasLanguageMapping: true,
                ),
            ]);
    }
}
