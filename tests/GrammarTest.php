<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Grammar;
use SocialWeb\Rdf\BlankNode;
use SocialWeb\Rdf\Exception\InvalidArgument;
use SocialWeb\Rdf\Iri;
use SocialWeb\Rdf\Literal;

class GrammarTest extends TestCase
{
    /**
     * The RDF package's Iri constructor is the definition of a well-formed
     * IRI; this package's copy of the rule must agree with it on every sample
     */
    #[DataProvider('iriSamples')]
    public function testIriWellFormednessAgreesWithTheRdfPackage(string $value): void
    {
        $this->assertSame(self::rdfAcceptsIri($value), Grammar::isWellFormedIri($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function iriSamples(): iterable
    {
        yield 'http' => ['http://example.com/'];
        yield 'https with path, query, and fragment' => ['https://example.com/path?q=1#frag'];
        yield 'urn' => ['urn:uuid:f81d4fae-7dec-11d0-a765-00a0c91e6bf6'];
        yield 'mailto' => ['mailto:someone@example.com'];
        yield 'non-ASCII path' => ['http://example.com/café'];
        yield 'scheme only' => ['a:'];
        yield 'blank node style' => ['_:b0'];
        yield 'empty' => [''];
        yield 'invalid UTF-8' => ["http://example.com/\xff"];
        yield 'relative path' => ['path/to/thing'];
        yield 'absolute path without scheme' => ['/path'];
        yield 'fragment only' => ['#frag'];
        yield 'scheme starting with a digit' => ['1http://example.com/'];
        yield 'space' => ['http://example.com/a b'];
        yield 'angle brackets' => ['http://example.com/<a>'];
        yield 'double quote' => ['http://example.com/"a"'];
        yield 'braces' => ['http://example.com/{a}'];
        yield 'pipe' => ['http://example.com/a|b'];
        yield 'caret' => ['http://example.com/a^b'];
        yield 'backtick' => ['http://example.com/a`b'];
        yield 'backslash' => ['http://example.com/a\\b'];
        yield 'control character' => ["http://example.com/a\x01b"];
        yield 'delete character' => ["http://example.com/a\x7fb"];
    }

    #[DataProvider('absoluteIriSamples')]
    public function testDetectsAScheme(string $value, bool $expected): void
    {
        $this->assertSame($expected, Grammar::isAbsoluteIri($value));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function absoluteIriSamples(): iterable
    {
        yield 'http' => ['http://example.com/', true];
        yield 'scheme with plus, dot, and hyphen' => ['a+b.c-d:x', true];
        yield 'scheme only' => ['a:', true];
        yield 'space after scheme' => ['http://example.com/a b', true];
        yield 'relative' => ['a/b', false];
        yield 'empty' => ['', false];
        yield 'leading digit' => ['1a:x', false];
        yield 'colon first' => [':x', false];
    }

    #[DataProvider('languageTagSamples')]
    public function testLanguageTagWellFormednessAgreesWithTheRdfPackage(string $tag): void
    {
        $this->assertSame(self::rdfAcceptsLanguageTag($tag), Grammar::isWellFormedLanguageTag($tag));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function languageTagSamples(): iterable
    {
        yield 'two letters' => ['en'];
        yield 'uppercase' => ['EN'];
        yield 'with region' => ['en-US'];
        yield 'with digits in subtag' => ['es-419'];
        yield 'many subtags' => ['zh-Hant-HK-x-private'];
        yield 'empty' => [''];
        yield 'leading digit' => ['1en'];
        yield 'digits in primary subtag' => ['e1'];
        yield 'trailing hyphen' => ['en-'];
        yield 'double hyphen' => ['en--US'];
        yield 'underscore' => ['en_US'];
        yield 'space' => ['en US'];
        yield 'non-ASCII' => ['én'];
    }

    #[DataProvider('blankNodeIdentifierSamples')]
    public function testBlankNodeIdentifierWellFormednessAgreesWithTheRdfPackage(string $identifier): void
    {
        $this->assertSame(
            self::rdfAcceptsBlankNodeIdentifier($identifier),
            Grammar::isWellFormedBlankNodeIdentifier($identifier),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankNodeIdentifierSamples(): iterable
    {
        yield 'letters and digits' => ['b0'];
        yield 'leading digit' => ['0'];
        yield 'leading underscore' => ['_x'];
        yield 'interior dot' => ['a.b'];
        yield 'interior hyphen' => ['a-b'];
        yield 'non-ASCII letters' => ['ñandú'];
        yield 'empty' => [''];
        yield 'leading hyphen' => ['-a'];
        yield 'leading dot' => ['.a'];
        yield 'trailing dot' => ['a.'];
        yield 'colon' => ['a:b'];
        yield 'space' => ['a b'];
        yield 'with prefix' => ['_:b0'];
    }

    private static function rdfAcceptsIri(string $value): bool
    {
        try {
            new Iri($value);
        } catch (InvalidArgument) {
            return false;
        }

        return true;
    }

    private static function rdfAcceptsLanguageTag(string $tag): bool
    {
        try {
            new Literal('x', language: $tag);
        } catch (InvalidArgument) {
            return false;
        }

        return true;
    }

    private static function rdfAcceptsBlankNodeIdentifier(string $identifier): bool
    {
        try {
            new BlankNode($identifier);
        } catch (InvalidArgument) {
            return false;
        }

        return true;
    }
}
