<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Expansion;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Expansion\ObjectForms;
use SocialWeb\Test\JsonLd\TestCase;

use function json_decode;

use const JSON_THROW_ON_ERROR;

class ObjectFormsTest extends TestCase
{
    #[DataProvider('forms')]
    public function testTellsTheFormsApart(string $json, bool $value, bool $list, bool $graph, bool $node): void
    {
        $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($value, ObjectForms::isValueObject($decoded));
        $this->assertSame($list, ObjectForms::isListObject($decoded));
        $this->assertSame($graph, ObjectForms::isGraphObject($decoded));
        $this->assertSame($node, ObjectForms::isNodeObject($decoded));
    }

    /**
     * @return iterable<string, array{string, bool, bool, bool, bool}>
     */
    public static function forms(): iterable
    {
        yield 'a value object' => ['{"@value": "A", "@language": "en"}', true, false, false, false];
        yield 'a value object whose value is null' => ['{"@value": null}', true, false, false, false];
        yield 'a list object' => ['{"@list": []}', false, true, false, false];
        yield 'a set object' => ['{"@set": []}', false, false, false, false];
        yield 'a graph object' => ['{"@graph": []}', false, false, true, true];
        yield 'a graph object with an identifier and an index' => [
            '{"@graph": [], "@id": "ex:g", "@index": "a"}',
            false,
            false,
            true,
            true,
        ];
        yield 'a node with a graph and a property' => ['{"@graph": [], "ex:p": []}', false, false, false, true];
        yield 'a node with an identifier and an index' => ['{"@id": "ex:a", "@index": "a"}', false, false, false, true];
        yield 'an empty map' => ['{}', false, false, false, true];
        yield 'a list' => ['[{"@value": 1}]', false, false, false, false];
        yield 'a string' => ['"@value"', false, false, false, false];
        yield 'null' => ['null', false, false, false, false];
    }
}
