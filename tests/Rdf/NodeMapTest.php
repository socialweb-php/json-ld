<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Rdf;

use SocialWeb\JsonLd\Rdf\NodeMap;
use SocialWeb\Test\JsonLd\TestCase;

use function array_map;

class NodeMapTest extends TestCase
{
    public function testStartsWithAnEmptyDefaultGraph(): void
    {
        $map = new NodeMap();

        $this->assertSame(['@default'], $map->graphNames());
        $this->assertSame([], $map->nodes('@default'));
        $this->assertSame([], $map->nodes('https://example.com/g'));
    }

    public function testMakesANodeOnceAndKeepsIt(): void
    {
        $map = new NodeMap();

        $node = $map->node('@default', 'https://example.com/a');
        $node->addProperty('https://example.com/p');

        $this->assertSame('https://example.com/a', $node->id);
        $this->assertSame($node, $map->node('@default', 'https://example.com/a'));
        $this->assertSame([$node], $map->nodes('@default'));
    }

    public function testMakesAGraphWithItsFirstNode(): void
    {
        $map = new NodeMap();

        $node = $map->node('https://example.com/g', '_:b0');

        $this->assertSame(['@default', 'https://example.com/g'], $map->graphNames());
        $this->assertSame([$node], $map->nodes('https://example.com/g'));
        $this->assertSame([], $map->nodes('@default'));
    }

    public function testTheSameIdentifierNamesADifferentNodeInEachGraph(): void
    {
        $map = new NodeMap();

        $inDefault = $map->node('@default', 'https://example.com/a');
        $inGraph = $map->node('https://example.com/g', 'https://example.com/a');

        $this->assertNotSame($inDefault, $inGraph);
        $this->assertSame($inDefault->id, $inGraph->id);
    }

    public function testOrdersGraphNamesByCodePoint(): void
    {
        $map = new NodeMap();

        foreach (['https://example.com/g', '_:b0', '', '123', 'Z'] as $name) {
            $map->node($name, '_:b0');
        }

        $this->assertSame(['', '123', '@default', 'Z', '_:b0', 'https://example.com/g'], $map->graphNames());
    }

    public function testOrdersNodesByCodePointOfTheirIdentifiers(): void
    {
        $map = new NodeMap();
        $ids = ['https://example.com/b', '_:b10', '_:b2', '', '123', 'https://example.com/a', 'Z'];

        foreach ($ids as $id) {
            $map->node('@default', $id);
        }

        $this->assertSame(
            ['', '123', 'Z', '_:b10', '_:b2', 'https://example.com/a', 'https://example.com/b'],
            array_map(static fn ($node): string => $node->id, $map->nodes('@default')),
        );
    }
}
