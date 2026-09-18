<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\IriResolver;

class IriResolverTest extends TestCase
{
    /**
     * The normal and abnormal examples of RFC 3986 section 5.4, against the
     * base `http://a/b/c/d;p?q`
     */
    #[DataProvider('rfc3986Examples')]
    public function testResolvesTheRfc3986Examples(string $reference, string $expected): void
    {
        $this->assertSame($expected, IriResolver::resolve($reference, 'http://a/b/c/d;p?q'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rfc3986Examples(): iterable
    {
        $examples = [
            'g:h' => 'g:h',
            'g' => 'http://a/b/c/g',
            './g' => 'http://a/b/c/g',
            'g/' => 'http://a/b/c/g/',
            '/g' => 'http://a/g',
            '//g' => 'http://g',
            '?y' => 'http://a/b/c/d;p?y',
            'g?y' => 'http://a/b/c/g?y',
            '#s' => 'http://a/b/c/d;p?q#s',
            'g#s' => 'http://a/b/c/g#s',
            'g?y#s' => 'http://a/b/c/g?y#s',
            ';x' => 'http://a/b/c/;x',
            'g;x' => 'http://a/b/c/g;x',
            'g;x?y#s' => 'http://a/b/c/g;x?y#s',
            '' => 'http://a/b/c/d;p?q',
            '.' => 'http://a/b/c/',
            './' => 'http://a/b/c/',
            '..' => 'http://a/b/',
            '../' => 'http://a/b/',
            '../g' => 'http://a/b/g',
            '../..' => 'http://a/',
            '../../' => 'http://a/',
            '../../g' => 'http://a/g',
            '../../../g' => 'http://a/g',
            '../../../../g' => 'http://a/g',
            '/./g' => 'http://a/g',
            '/../g' => 'http://a/g',
            'g.' => 'http://a/b/c/g.',
            '.g' => 'http://a/b/c/.g',
            'g..' => 'http://a/b/c/g..',
            '..g' => 'http://a/b/c/..g',
            './../g' => 'http://a/b/g',
            './g/.' => 'http://a/b/c/g/',
            'g/./h' => 'http://a/b/c/g/h',
            'g/../h' => 'http://a/b/c/h',
            'g;x=1/./y' => 'http://a/b/c/g;x=1/y',
            'g;x=1/../y' => 'http://a/b/c/y',
            'g?y/./x' => 'http://a/b/c/g?y/./x',
            'g?y/../x' => 'http://a/b/c/g?y/../x',
            'g#s/./x' => 'http://a/b/c/g#s/./x',
            'g#s/../x' => 'http://a/b/c/g#s/../x',
            'http:g' => 'http:g',
        ];

        foreach ($examples as $reference => $expected) {
            yield '"' . $reference . '"' => [(string) $reference, $expected];
        }
    }

    #[DataProvider('otherCases')]
    public function testResolvesCasesTheExamplesDoNotCover(string $reference, string $base, string $expected): void
    {
        $this->assertSame($expected, IriResolver::resolve($reference, $base));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function otherCases(): iterable
    {
        yield 'base with authority and no path' => ['g', 'http://a', 'http://a/g'];
        yield 'base with authority, no path, and a query' => ['g', 'http://a?q', 'http://a/g'];
        yield 'empty reference against a base with a fragment' => ['', 'http://a/b#f', 'http://a/b'];
        yield 'fragment against a base with a fragment' => ['#g', 'http://a/b#f', 'http://a/b#g'];
        yield 'query keeps the base path' => ['?x', 'http://a/b/c', 'http://a/b/c?x'];
        yield 'empty query is kept' => ['?', 'http://a/b', 'http://a/b?'];
        yield 'empty fragment is kept' => ['#', 'http://a/b', 'http://a/b#'];
        yield 'absolute reference with dot segments is cleaned' => ['http://x/a/./b/../c', 'http://a/', 'http://x/a/c'];
        yield 'no normalization of case' => ['G', 'HTTP://A/b/', 'HTTP://A/b/G'];
        yield 'no normalization of percent-encoding' => ['%7Eg', 'http://a/b/', 'http://a/b/%7Eg'];
        yield 'default port is kept' => ['g', 'http://a:80/b/', 'http://a:80/b/g'];
        yield 'urn base with a relative reference' => ['g', 'urn:isbn:1', 'urn:g'];
        yield 'urn base with a dot reference' => ['./g', 'urn:a/b', 'urn:a/g'];
        yield 'urn base with a parent reference' => ['../g', 'urn:a/b/c', 'urn:a/g'];
        yield 'non-ASCII path' => ['ö', 'http://a/b/', 'http://a/b/ö'];
        yield 'trailing dot-dot keeps a slash' => ['b/..', 'http://a/x/', 'http://a/x/'];
    }
}
