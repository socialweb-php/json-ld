<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Keywords;

class KeywordsTest extends TestCase
{
    #[DataProvider('samples')]
    public function testTellsKeywordsFromStringsThatOnlyLookLikeOne(
        string $value,
        bool $isKeyword,
        bool $hasKeywordForm,
    ): void {
        $this->assertSame($isKeyword, Keywords::isKeyword($value));
        $this->assertSame($hasKeywordForm, Keywords::hasKeywordForm($value));
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function samples(): iterable
    {
        $keywords = [
            '@base',
            '@container',
            '@context',
            '@direction',
            '@graph',
            '@id',
            '@import',
            '@included',
            '@index',
            '@json',
            '@language',
            '@list',
            '@nest',
            '@none',
            '@prefix',
            '@propagate',
            '@protected',
            '@reverse',
            '@set',
            '@type',
            '@value',
            '@version',
            '@vocab',
        ];

        foreach ($keywords as $keyword) {
            yield $keyword => [$keyword, true, true];
        }

        yield 'unknown word after @' => ['@ignoreMe', false, true];
        yield 'keyword in another case' => ['@ID', false, true];
        yield 'a bare @' => ['@', false, false];
        yield 'a digit after @' => ['@1', false, false];
        yield 'a letter then a digit after @' => ['@a1', false, false];
        yield 'an email address' => ['user@example.com', false, false];
        yield 'text before the @' => ['x@id', false, false];
        yield 'a line break after the word' => ["@id\n", false, false];
        yield 'a term' => ['name', false, false];
        yield 'the empty string' => ['', false, false];
    }
}
