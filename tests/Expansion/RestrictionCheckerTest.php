<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Expansion;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Exception\RestrictedFeature;
use SocialWeb\JsonLd\Expansion\RestrictionChecker;
use SocialWeb\JsonLd\Settings;
use SocialWeb\Test\JsonLd\TestCase;

use function array_is_list;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

final class RestrictionCheckerTest extends TestCase
{
    private const string NAMED_GRAPH = '[{"@id": "ex:g", "@graph": [{"@id": "ex:a", "ex:p": [{"@value": 1}]}]}]';

    private const string INCLUDED_BLOCK = '[{"@id": "ex:a", "@included": [{"@id": "ex:b", "ex:p": [{"@value": 1}]}]}]';

    private const string REVERSE_PROPERTY = '[{"@id": "ex:a", "@reverse": {"ex:parent": [{"@id": "ex:b"}]}}]';

    private const string TWO_NODES = '[{"@id": "ex:a", "ex:p": [{"@value": 1}]},'
        . ' {"@id": "ex:b", "ex:p": [{"@value": 2}]}]';

    #[DataProvider('documents')]
    public function testAllowsEverythingByDefault(string $document): void
    {
        $this->expectNotToPerformAssertions();

        RestrictionChecker::check(new Settings(), self::nodes($document));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'a named graph' => [self::NAMED_GRAPH];
        yield 'an included block' => [self::INCLUDED_BLOCK];
        yield 'a reverse property' => [self::REVERSE_PROPERTY];
        yield 'two top-level nodes' => [self::TWO_NODES];
        yield 'no nodes' => ['[]'];
    }

    #[DataProvider('violations')]
    public function testRefusesAFeature(
        Settings $settings,
        string $document,
        string $restriction,
        string $detail,
    ): void {
        try {
            RestrictionChecker::check($settings, self::nodes($document));
            $this->fail('Expected a RestrictedFeature');
        } catch (RestrictedFeature $exception) {
            $this->assertSame($restriction, $exception->restriction);
            $this->assertSame($detail, $exception->detail);
        }
    }

    /**
     * @return iterable<string, array{Settings, string, string, string}>
     */
    public static function violations(): iterable
    {
        $noGraphs = new Settings(forbidNamedGraphs: true);
        $noIncluded = new Settings(forbidIncludedBlocks: true);
        $noReverse = new Settings(forbidReverseProperties: true);
        $single = new Settings(requireSingleTopLevelNode: true);

        yield 'a named graph' => [$noGraphs, self::NAMED_GRAPH, 'forbidNamedGraphs', 'ex:g'];
        yield 'an included block' => [$noIncluded, self::INCLUDED_BLOCK, 'forbidIncludedBlocks', 'ex:a'];
        yield 'a reverse property' => [$noReverse, self::REVERSE_PROPERTY, 'forbidReverseProperties', 'ex:a'];
        yield 'two top-level nodes' => [$single, self::TWO_NODES, 'requireSingleTopLevelNode', '2'];
        yield 'no top-level node' => [$single, '[]', 'requireSingleTopLevelNode', '0'];
        yield 'a named graph without an identifier' => [
            $noGraphs,
            '[{"@graph": [{"@id": "ex:a", "ex:p": [{"@value": 1}]}], "ex:q": [{"@value": 1}]}]',
            'forbidNamedGraphs',
            'a node with no @id',
        ];
        yield 'a named graph whose identifier is null' => [
            $noGraphs,
            '[{"@id": null, "@graph": []}]',
            'forbidNamedGraphs',
            'a node with no @id',
        ];
        yield 'a graph object that is the value of a property' => [
            $noGraphs,
            '[{"@id": "ex:a", "ex:p": [{"@graph": [{"@id": "ex:b"}], "@id": "ex:g"}]}]',
            'forbidNamedGraphs',
            'ex:g',
        ];
        yield 'an included block inside a list' => [
            $noIncluded,
            '[{"@id": "ex:a", "ex:p": [{"@list": [{"@id": "ex:b", "@included": []}]}]}]',
            'forbidIncludedBlocks',
            'ex:b',
        ];
        yield 'a reverse property inside a reverse map' => [
            new Settings(forbidIncludedBlocks: true),
            '[{"@id": "ex:a", "@reverse": {"ex:parent": [{"@id": "ex:b", "@included": []}]}}]',
            'forbidIncludedBlocks',
            'ex:b',
        ];
        yield 'a reverse property inside a named graph' => [
            $noReverse,
            '[{"@id": "ex:g", "@graph": [{"@id": "ex:a", "@reverse": {"ex:parent": [{"@id": "ex:b"}]}}]}]',
            'forbidReverseProperties',
            'ex:a',
        ];
        yield 'a reverse property inside an included block' => [
            $noReverse,
            '[{"@id": "ex:c", "@included": [{"@id": "ex:a", "@reverse": {"ex:parent": [{"@id": "ex:b"}]}}]}]',
            'forbidReverseProperties',
            'ex:a',
        ];
        yield 'the top-level count comes first' => [
            new Settings(
                forbidNamedGraphs: true,
                forbidIncludedBlocks: true,
                forbidReverseProperties: true,
                requireSingleTopLevelNode: true,
            ),
            self::TWO_NODES,
            'requireSingleTopLevelNode',
            '2',
        ];
        yield 'named graphs come before included blocks' => [
            new Settings(
                forbidNamedGraphs: true,
                forbidIncludedBlocks: true,
                forbidReverseProperties: true,
                requireSingleTopLevelNode: true,
            ),
            '[{"@id": "ex:a", "@included": [], "@graph": [], "@reverse": {}}]',
            'forbidNamedGraphs',
            'ex:a',
        ];
        yield 'included blocks come before reverse properties' => [
            new Settings(
                forbidNamedGraphs: true,
                forbidIncludedBlocks: true,
                forbidReverseProperties: true,
                requireSingleTopLevelNode: true,
            ),
            '[{"@id": "ex:a", "@reverse": {}, "@included": []}]',
            'forbidIncludedBlocks',
            'ex:a',
        ];
        yield 'a node is checked before the nodes inside it' => [
            new Settings(
                forbidNamedGraphs: true,
                forbidIncludedBlocks: true,
                forbidReverseProperties: true,
                requireSingleTopLevelNode: true,
            ),
            '[{"@id": "ex:a", "ex:p": [{"@id": "ex:b", "@graph": []}], "@reverse": {}}]',
            'forbidReverseProperties',
            'ex:a',
        ];
    }

    #[DataProvider('restrictionsThatDoNotApply')]
    public function testEachRestrictionLooksForItsOwnFeatureOnly(Settings $settings, string $document): void
    {
        $this->expectNotToPerformAssertions();

        RestrictionChecker::check($settings, self::nodes($document));
    }

    /**
     * @return iterable<string, array{Settings, string}>
     */
    public static function restrictionsThatDoNotApply(): iterable
    {
        $allButGraphs = new Settings(
            forbidNamedGraphs: false,
            forbidIncludedBlocks: true,
            forbidReverseProperties: true,
            requireSingleTopLevelNode: true,
        );
        $allButIncluded = new Settings(
            forbidNamedGraphs: true,
            forbidIncludedBlocks: false,
            forbidReverseProperties: true,
            requireSingleTopLevelNode: true,
        );
        $allButReverse = new Settings(
            forbidNamedGraphs: true,
            forbidIncludedBlocks: true,
            forbidReverseProperties: false,
            requireSingleTopLevelNode: true,
        );
        $allButSingle = new Settings(
            forbidNamedGraphs: true,
            forbidIncludedBlocks: true,
            forbidReverseProperties: true,
            requireSingleTopLevelNode: false,
        );

        yield 'a named graph' => [$allButGraphs, self::NAMED_GRAPH];
        yield 'an included block' => [$allButIncluded, self::INCLUDED_BLOCK];
        yield 'a reverse property' => [$allButReverse, self::REVERSE_PROPERTY];
        yield 'two top-level nodes' => [$allButSingle, self::TWO_NODES];
        yield 'one plain node' => [
            new Settings(
                forbidNamedGraphs: true,
                forbidIncludedBlocks: true,
                forbidReverseProperties: true,
                requireSingleTopLevelNode: true,
            ),
            '[{"@id": "ex:a", "@type": ["ex:T"], "ex:p": [{"@value": 1}, {"@list": [{"@id": "ex:b"}]}]}]',
        ];
    }

    public function testTheContentsOfAJsonLiteralAreData(): void
    {
        $this->expectNotToPerformAssertions();

        RestrictionChecker::check(
            new Settings(
                forbidNamedGraphs: true,
                forbidIncludedBlocks: true,
                forbidReverseProperties: true,
                requireSingleTopLevelNode: true,
            ),
            self::nodes(
                '[{"@id": "ex:a", "ex:p": [{"@type": "@json",'
                    . ' "@value": {"@graph": [], "@included": [], "@reverse": {}, "deeper": [{"@graph": []}]}}]}]',
            ),
        );
    }

    /**
     * @return list<mixed>
     */
    private static function nodes(string $document): array
    {
        $nodes = json_decode($document, flags: JSON_THROW_ON_ERROR);

        if (!is_array($nodes) || !array_is_list($nodes)) {
            throw new LogicException('An expanded document is a JSON array');
        }

        return $nodes;
    }
}
