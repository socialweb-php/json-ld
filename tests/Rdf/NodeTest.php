<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Rdf;

use SocialWeb\JsonLd\Rdf\Node;
use SocialWeb\Test\JsonLd\TestCase;

use function json_decode;

use const JSON_THROW_ON_ERROR;

class NodeTest extends TestCase
{
    public function testStartsWithOnlyAnIdentifier(): void
    {
        $node = new Node('https://example.com/a');

        $this->assertSame('https://example.com/a', $node->id);
        $this->assertSame([], $node->types);
        $this->assertNull($node->index);
        $this->assertSame([], $node->properties);
    }

    public function testAddsATypeOnce(): void
    {
        $node = new Node('_:b0');

        $node->addType('https://example.com/T');
        $node->addType('_:b1');
        $node->addType('https://example.com/T');
        $node->addType(null);
        $node->addType(null);

        $this->assertSame(['https://example.com/T', '_:b1', null], $node->types);
    }

    public function testSetsTheIndex(): void
    {
        $node = new Node('_:b0');

        $node->setIndex('first');
        $this->assertSame('first', $node->index);

        $node->setIndex('second');
        $this->assertSame('second', $node->index);
    }

    public function testAddingAPropertyMakesAnEmptyListOnce(): void
    {
        $node = new Node('_:b0');
        $reference = (object) ['@id' => 'https://example.com/b'];

        $node->addProperty('https://example.com/p');
        $this->assertSame(['https://example.com/p' => []], $node->properties);

        $node->append('https://example.com/p', $reference);
        $node->addProperty('https://example.com/p');
        $this->assertSame(['https://example.com/p' => [$reference]], $node->properties);
    }

    public function testAppendKeepsEveryItem(): void
    {
        $node = new Node('_:b0');
        $list = (object) ['@list' => []];

        $node->append('https://example.com/p', $list);
        $node->append('https://example.com/p', $list);
        $node->append('https://example.com/q', $list);

        $this->assertSame(
            ['https://example.com/p' => [$list, $list], 'https://example.com/q' => [$list]],
            $node->properties,
        );
    }

    public function testAppendOnceLeavesOutAnEquivalentItem(): void
    {
        $node = new Node('_:b0');
        $one = self::value('{"@value": 1}');
        $oneAgain = self::value('{"@value": 1}');
        $oneAsFloat = self::value('{"@value": 1.0}');
        $oneAsString = self::value('{"@value": "1"}');
        $reference = self::value('{"@id": "https://example.com/b"}');
        $referenceAgain = self::value('{"@id": "https://example.com/b"}');
        $json = self::value('{"@type": "@json", "@value": {"b": [1, {}], "a": "x"}}');
        $jsonInAnotherOrder = self::value('{"@value": {"a": "x", "b": [1, {}]}, "@type": "@json"}');
        $jsonWithAnotherList = self::value('{"@type": "@json", "@value": {"b": [{}, 1], "a": "x"}}');

        foreach (
            [
                $one,
                $oneAgain,
                $oneAsFloat,
                $oneAsString,
                $reference,
                $referenceAgain,
                $json,
                $jsonInAnotherOrder,
                $jsonWithAnotherList,
            ] as $item
        ) {
            $node->appendOnce('https://example.com/p', $item);
        }

        $this->assertSame(
            ['https://example.com/p' => [$one, $oneAsString, $reference, $json, $jsonWithAnotherList]],
            $node->properties,
        );
    }

    public function testEquivalenceIsJudgedForEachPropertyOnItsOwn(): void
    {
        $node = new Node('_:b0');
        $value = self::value('{"@value": "x", "@language": "en"}');

        $node->appendOnce('https://example.com/p', $value);
        $node->appendOnce('https://example.com/q', $value);
        $node->appendOnce('https://example.com/q', $value);
        $node->addType('https://example.com/p');

        $this->assertSame(
            ['https://example.com/p' => [$value], 'https://example.com/q' => [$value]],
            $node->properties,
        );
        $this->assertSame(['https://example.com/p'], $node->types);
    }

    public function testAnItemAppendedWithoutTheCheckIsNotSeenByIt(): void
    {
        $node = new Node('_:b0');
        $list = self::value('{"@list": []}');
        $listAgain = self::value('{"@list": []}');

        $node->append('https://example.com/p', $list);
        $node->appendOnce('https://example.com/p', $listAgain);

        $this->assertSame(['https://example.com/p' => [$list, $listAgain]], $node->properties);
    }

    private static function value(string $json): mixed
    {
        return json_decode($json, flags: JSON_THROW_ON_ERROR);
    }
}
