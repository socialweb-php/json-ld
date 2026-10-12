<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Rdf;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\Rdf\BlankNodeIssuer;
use SocialWeb\JsonLd\Rdf\NodeMap;
use SocialWeb\JsonLd\Rdf\NodeMapGenerator;
use SocialWeb\JsonLd\Rdf\RdfConverter;
use SocialWeb\JsonLd\RdfDirection;
use SocialWeb\Rdf\Dataset;
use SocialWeb\Rdf\NQuads\Serializer;
use SocialWeb\Test\JsonLd\TestCase;

use function array_is_list;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Expected datasets are written as N-Quads in the order the algorithm adds
 * the quads: graphs, subjects, and properties in code point order, and the
 * triples of a list or a compound literal after the triple that holds its
 * head.
 */
class RdfConverterTest extends TestCase
{
    #[DataProvider('documents')]
    public function testConverts(string $expected, string $document): void
    {
        $this->assertSame($expected, self::nquads(self::convert($document)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'no nodes' => ['', '[]'];

        yield 'a node with only an identifier makes no quad' => ['', '[{"@id": "ex:a"}]'];

        yield 'a property with no values makes no quad' => ['', '[{"@id": "ex:a", "ex:p": []}]'];

        yield 'a node that is a subject only in a named graph' => [
            <<<'NQUADS'
            <ex:b> <ex:p> "x" <ex:g> .

            NQUADS,
            '[{"@id": "ex:g", "@graph": [{"@id": "ex:b", "ex:p": [{"@value": "x"}]}]}]',
        ];

        // `@default` sorts before `_:`, which sorts before every IRI, so the
        // default graph comes first and a blank node graph or subject before
        // an IRI; `_:b1` is the blank node graph, met before the list is.
        yield 'types, values, references, and a list, with graphs, subjects, and properties in code point order' => [
            <<<'NQUADS'
            _:b0 <ex:p> "z" .
            <ex:a> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <ex:T> .
            <ex:a> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> _:b0 .
            <ex:a> <ex:p> "x" .
            <ex:a> <ex:p> <ex:b> .
            <ex:a> <ex:q> _:b2 .
            _:b2 <http://www.w3.org/1999/02/22-rdf-syntax-ns#first> "1"^^<http://www.w3.org/2001/XMLSchema#integer> .
            _:b2 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> <http://www.w3.org/1999/02/22-rdf-syntax-ns#nil> .
            <ex:b> <ex:p> "y"@en .
            _:b1 <ex:p> "in a blank node graph" _:b1 .
            <ex:a> <ex:p> "in g" <ex:g> .

            NQUADS,
            '[
                {
                    "@id": "ex:a",
                    "@type": ["ex:T", "_:t"],
                    "ex:q": [{"@list": [{"@value": 1}]}],
                    "ex:p": [
                        {"@value": "x"},
                        {"@id": "ex:b", "ex:p": [{"@value": "y", "@language": "en"}]}
                    ]
                },
                {"@id": "_:t", "ex:p": [{"@value": "z"}]},
                {"@id": "ex:g", "@graph": [
                    {"@id": "ex:a", "ex:p": [{"@value": "in g"}]}
                ]},
                {"@id": "_:g", "@graph": [
                    {"@id": "_:g", "ex:p": [{"@value": "in a blank node graph"}]}
                ]}
            ]',
        ];

        yield 'two parts of the document that say the same thing make one quad' => [
            <<<'NQUADS'
            <ex:a> <ex:p> "x" .

            NQUADS,
            '[
                {"@id": "ex:a", "ex:p": [{"@value": "x"}]},
                {"@id": "ex:a", "ex:p": [{"@value": "x"}]}
            ]',
        ];

        yield 'in lenient mode, what is not well-formed is left out and the rest is kept' => [
            <<<'NQUADS'
            <ex:a> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <ex:T> .
            <ex:a> <ex:p> "kept" .
            <ex:a> <ex:q> <ex:b> .

            NQUADS,
            '[
                {
                    "@id": "ex:a",
                    "@type": [null, "relative/type", "ex:T"],
                    "ex:p": [{"@value": "kept"}, {"@value": "x", "@language": "a b"}],
                    "ex:q": [{"@id": "ex:b"}, {"@id": "relative/object"}],
                    "relative/predicate": [{"@value": "x"}],
                    "_:p": [{"@value": "x"}],
                    "123": [{"@value": "x"}],
                    "@set": [{"@value": "x"}]
                },
                {"@id": "relative/subject", "ex:p": [{"@value": "x"}]},
                {"@id": null, "ex:p": [{"@value": "x"}]},
                {"@id": "relative/graph", "@graph": [
                    {"@id": "ex:a", "ex:p": [{"@value": "x"}]}
                ]}
            ]',
        ];
    }

    public function testListAndCompoundLiteralBlankNodesFollowTheNodeMapsLabels(): void
    {
        $this->assertSame(
            <<<'NQUADS'
            <ex:a> <ex:p> _:b0 .
            <ex:a> <ex:q> _:b1 .
            _:b1 <http://www.w3.org/1999/02/22-rdf-syntax-ns#first> _:b0 .
            _:b1 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> _:b2 .
            _:b2 <http://www.w3.org/1999/02/22-rdf-syntax-ns#first> _:b3 .
            _:b2 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> <http://www.w3.org/1999/02/22-rdf-syntax-ns#nil> .
            _:b3 <http://www.w3.org/1999/02/22-rdf-syntax-ns#value> "x" .
            _:b3 <http://www.w3.org/1999/02/22-rdf-syntax-ns#direction> "rtl" .

            NQUADS,
            self::nquads(self::convert(
                '[{
                    "@id": "ex:a",
                    "ex:p": [{"@id": "_:x"}],
                    "ex:q": [{"@list": [{"@id": "_:x"}, {"@value": "x", "@direction": "rtl"}]}]
                }]',
                new Options(strict: false, rdfDirection: RdfDirection::CompoundLiteral),
            )),
        );
    }

    #[DataProvider('documentsThatLoseData')]
    public function testRefusesToDropDataInStrictMode(
        string $document,
        DataLossCondition $condition,
        string $detail,
    ): void {
        try {
            self::convert($document, new Options());
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame($condition, $dataLoss->condition);
            $this->assertSame($detail, $dataLoss->detail);
        }
    }

    /**
     * @return iterable<string, array{string, DataLossCondition, string}>
     */
    public static function documentsThatLoseData(): iterable
    {
        $a = '"@id": "ex:a"';

        // A graph's node is also a subject of the graph that holds it, so the
        // graph name is checked first only when it sorts before that graph.
        yield 'a graph name that is not well-formed' => [
            '[{"@id": "ex:z", "@graph": [{"@id": "ex:a b", "@graph": [{' . $a . '}]}]}]',
            DataLossCondition::RelativeGraph,
            'ex:a b',
        ];
        yield 'a relative subject' => [
            '[{"@id": "relative/subject"}]',
            DataLossCondition::RelativeSubject,
            'relative/subject',
        ];
        yield 'the empty subject that lenient expansion leaves for a null identifier' => [
            '[{"@id": null}]',
            DataLossCondition::RelativeSubject,
            '',
        ];
        yield 'a relative type' => [
            '[{' . $a . ', "@type": ["relative/type"]}]',
            DataLossCondition::RelativeType,
            'relative/type',
        ];
        yield 'the null type that lenient expansion leaves' => [
            '[{' . $a . ', "@type": [null]}]',
            DataLossCondition::RelativeType,
            'null',
        ];
        yield 'a keyword as a property, which a typed set map leaves' => [
            '[{' . $a . ', "@set": [{"@value": "x"}]}]',
            DataLossCondition::UndefinedProperty,
            '@set',
        ];
        yield 'a blank node predicate' => [
            '[{' . $a . ', "_:p": [{"@value": "x"}]}]',
            DataLossCondition::BlankNodePredicate,
            '_:b0',
        ];
        yield 'a relative predicate' => [
            '[{' . $a . ', "relative/predicate": [{"@value": "x"}]}]',
            DataLossCondition::RelativePredicate,
            'relative/predicate',
        ];
        yield 'a predicate that is only digits, which PHP stores as an integer key' => [
            '[{' . $a . ', "123": [{"@value": "x"}]}]',
            DataLossCondition::RelativePredicate,
            '123',
        ];
        yield 'a relative object' => [
            '[{' . $a . ', "ex:p": [{"@id": "relative/object"}]}]',
            DataLossCondition::RelativeObject,
            'relative/object',
        ];
        yield 'a language tag that is not well-formed' => [
            '[{' . $a . ', "ex:p": [{"@value": "x", "@language": "a b"}]}]',
            DataLossCondition::MalformedLanguageTag,
            'a b',
        ];
        yield 'a direction with no rdfDirection option' => [
            '[{' . $a . ', "ex:p": [{"@value": "x", "@direction": "rtl"}]}]',
            DataLossCondition::DroppedDirection,
            '{"@direction":"rtl","@value":"x"}',
        ];
    }

    public function testABlankNodeIdentifierThatIsNotWellFormedIsSkippedOrRefused(): void
    {
        // Node map generation relabels every blank node, so only a node map
        // built by hand can hold such an identifier.
        $map = new NodeMap();
        $map->node(NodeMap::DEFAULT_GRAPH, '_:not valid')->append('ex:p', (object) ['@value' => 'x']);
        $map->node('_:not valid', 'ex:a')->append('ex:p', (object) ['@value' => 'y']);
        $map->node(NodeMap::DEFAULT_GRAPH, 'ex:a')->addType('_:not valid');

        $this->assertSame(
            '',
            self::nquads(RdfConverter::convert($map, new BlankNodeIssuer(), new Options(strict: false))),
        );

        try {
            RdfConverter::convert($map, new BlankNodeIssuer(), new Options());
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame(DataLossCondition::MalformedBlankNodeIdentifier, $dataLoss->condition);
            $this->assertSame('_:not valid', $dataLoss->detail);
        }
    }

    public function testVisitsThePropertiesOfANodeBuiltByHandInCodePointOrder(): void
    {
        $map = new NodeMap();
        $node = $map->node(NodeMap::DEFAULT_GRAPH, 'ex:a');
        $node->append('ex:q', (object) ['@value' => 'q']);
        $node->append('ex:p', (object) ['@value' => 'p']);

        $this->assertSame(
            <<<'NQUADS'
            <ex:a> <ex:p> "p" .
            <ex:a> <ex:q> "q" .

            NQUADS,
            self::nquads(RdfConverter::convert($map, new BlankNodeIssuer(), new Options())),
        );
    }

    public function testRefusesANodeMapThatIsNotInExpandedForm(): void
    {
        $map = new NodeMap();
        $map->node(NodeMap::DEFAULT_GRAPH, 'ex:a')->append('ex:p', 1);

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('An item of a node map must be a map');

        RdfConverter::convert($map, new BlankNodeIssuer(), new Options());
    }

    private static function convert(string $document, ?Options $options = null): Dataset
    {
        $options ??= new Options(strict: false);
        $nodes = json_decode($document, flags: JSON_THROW_ON_ERROR);

        if (!is_array($nodes) || !array_is_list($nodes)) {
            throw new LogicException('An expanded document is a JSON array');
        }

        $issuer = new BlankNodeIssuer();

        return RdfConverter::convert(NodeMapGenerator::generate($nodes, $issuer, $options->strict), $issuer, $options);
    }

    private static function nquads(Dataset $dataset): string
    {
        return (new Serializer())->serialize($dataset);
    }
}
