<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\Test\JsonLd\TestCase;

use function json_decode;

use const JSON_THROW_ON_ERROR;

class JsonLdComparisonTest extends TestCase
{
    #[DataProvider('pairs')]
    public function testComparesAsTheSuiteDefines(bool $same, string $one, string $two): void
    {
        $this->assertSame(
            $same,
            JsonLdComparison::canonicalize(json_decode($one, flags: JSON_THROW_ON_ERROR))
                === JsonLdComparison::canonicalize(json_decode($two, flags: JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * @return iterable<string, array{bool, string, string}>
     */
    public static function pairs(): iterable
    {
        yield 'the order of entries does not matter' => [true, '[{"a": [1], "b": [2]}]', '[{"b": [2], "a": [1]}]'];
        yield 'the order of an array does not matter' => [
            true,
            '[{"p": [{"@value": 1}, {"@value": 2}]}, {"q": []}]',
            '[{"q": []}, {"p": [{"@value": 2}, {"@value": 1}]}]',
        ];
        yield 'the order of a list matters' => [
            false,
            '[{"p": [{"@list": [{"@value": 1}, {"@value": 2}]}]}]',
            '[{"p": [{"@list": [{"@value": 2}, {"@value": 1}]}]}]',
        ];
        yield 'the order of an array inside a list item does not matter' => [
            true,
            '[{"p": [{"@list": [{"q": [{"@value": 1}, {"@value": 2}]}]}]}]',
            '[{"p": [{"@list": [{"q": [{"@value": 2}, {"@value": 1}]}]}]}]',
        ];
        yield 'the order of an array in a JSON literal matters' => [
            false,
            '[{"p": [{"@type": "@json", "@value": [1, 2]}]}]',
            '[{"p": [{"@type": "@json", "@value": [2, 1]}]}]',
        ];
        yield 'a different value' => [false, '[{"p": [{"@value": 1}]}]', '[{"p": [{"@value": "1"}]}]'];
        yield 'a missing item' => [false, '[{"p": [{"@value": 1}]}]', '[{"p": [{"@value": 1}, {"@value": 1}]}]'];
    }
}
