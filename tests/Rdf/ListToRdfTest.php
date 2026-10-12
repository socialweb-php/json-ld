<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Rdf;

use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Rdf\BlankNodeIssuer;
use SocialWeb\JsonLd\Rdf\ListToRdf;
use SocialWeb\JsonLd\Rdf\ObjectToRdf;
use SocialWeb\JsonLd\RdfDirection;
use SocialWeb\Rdf\BlankNode;
use SocialWeb\Rdf\DefaultGraph;
use SocialWeb\Rdf\Iri;
use SocialWeb\Rdf\NQuads\Serializer;
use SocialWeb\Rdf\Quad;
use SocialWeb\Test\JsonLd\TestCase;

use function array_map;
use function implode;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class ListToRdfTest extends TestCase
{
    public function testAnEmptyListIsNil(): void
    {
        $triples = [];
        $head = self::lists()->convert([], new DefaultGraph(), $triples);

        $this->assertTrue($head->equals(new Iri('http://www.w3.org/1999/02/22-rdf-syntax-ns#nil')));
        $this->assertSame([], $triples);
    }

    public function testEachItemGetsABlankNodeWithFirstAndRest(): void
    {
        $triples = [];
        $head = self::lists()->convert(
            self::items('[{"@value": "a"}, {"@id": "ex:b"}, {"@value": 1}]'),
            new DefaultGraph(),
            $triples,
        );

        $this->assertTrue($head->equals(new BlankNode('b0')));
        $this->assertSame(
            <<<'NQUADS'
            _:b0 <http://www.w3.org/1999/02/22-rdf-syntax-ns#first> "a" .
            _:b0 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> _:b1 .
            _:b1 <http://www.w3.org/1999/02/22-rdf-syntax-ns#first> <ex:b> .
            _:b1 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> _:b2 .
            _:b2 <http://www.w3.org/1999/02/22-rdf-syntax-ns#first> "1"^^<http://www.w3.org/2001/XMLSchema#integer> .
            _:b2 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> <http://www.w3.org/1999/02/22-rdf-syntax-ns#nil> .

            NQUADS,
            self::nquads($triples),
        );
    }

    public function testTheTriplesAreInTheGraphOfTheList(): void
    {
        $graph = new Iri('ex:g');
        $triples = [];
        self::lists()->convert(self::items('[{"@value": "a"}, {"@list": [{"@value": "b"}]}]'), $graph, $triples);

        $this->assertCount(6, $triples);

        foreach ($triples as $triple) {
            $this->assertTrue($triple->graph->equals($graph));
        }
    }

    public function testTheTriplesOfAnItemFollowTheItemsOwnTriples(): void
    {
        $triples = [];
        self::lists(RdfDirection::CompoundLiteral)->convert(
            self::items('[{"@list": [{"@value": "inner"}]}, {"@value": "x", "@direction": "rtl"}]'),
            new DefaultGraph(),
            $triples,
        );

        // The outer list's blank nodes are issued first, then the inner
        // list's, then the compound literal's, in the order the algorithm
        // meets them; each item's embedded triples come after its own.
        $this->assertSame(
            <<<'NQUADS'
            _:b0 <http://www.w3.org/1999/02/22-rdf-syntax-ns#first> _:b2 .
            _:b0 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> _:b1 .
            _:b2 <http://www.w3.org/1999/02/22-rdf-syntax-ns#first> "inner" .
            _:b2 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> <http://www.w3.org/1999/02/22-rdf-syntax-ns#nil> .
            _:b1 <http://www.w3.org/1999/02/22-rdf-syntax-ns#first> _:b3 .
            _:b1 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> <http://www.w3.org/1999/02/22-rdf-syntax-ns#nil> .
            _:b3 <http://www.w3.org/1999/02/22-rdf-syntax-ns#value> "x" .
            _:b3 <http://www.w3.org/1999/02/22-rdf-syntax-ns#direction> "rtl" .

            NQUADS,
            self::nquads($triples),
        );
    }

    public function testAnItemThatIsNotWellFormedLeavesAGapInLenientMode(): void
    {
        $triples = [];
        $head = self::lists()->convert(
            self::items('[{"@id": "relative"}, {"@value": "a"}]'),
            new DefaultGraph(),
            $triples,
        );

        $this->assertTrue($head->equals(new BlankNode('b0')));
        $this->assertSame(
            <<<'NQUADS'
            _:b0 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> _:b1 .
            _:b1 <http://www.w3.org/1999/02/22-rdf-syntax-ns#first> "a" .
            _:b1 <http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> <http://www.w3.org/1999/02/22-rdf-syntax-ns#nil> .

            NQUADS,
            self::nquads($triples),
        );
    }

    public function testAnItemThatIsNotWellFormedIsRefusedInStrictMode(): void
    {
        $triples = [];

        try {
            self::lists(strict: true)->convert(self::items('[{"@id": "relative"}]'), new DefaultGraph(), $triples);
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame(DataLossCondition::RelativeObject, $dataLoss->condition);
            $this->assertSame('relative', $dataLoss->detail);
        }
    }

    public function testTakesItsBlankNodesFromTheIssuerItIsGiven(): void
    {
        $issuer = new BlankNodeIssuer();
        $issuer->issue('_:a');
        $issuer->issue('_:b');
        $triples = [];

        $head = self::lists(issuer: $issuer)->convert(self::items('[{"@value": 1}]'), new DefaultGraph(), $triples);

        $this->assertTrue($head->equals(new BlankNode('b2')));
        $this->assertSame('_:b3', $issuer->issue());
    }

    private static function lists(
        ?RdfDirection $rdfDirection = null,
        bool $strict = false,
        ?BlankNodeIssuer $issuer = null,
    ): ListToRdf {
        $issuer ??= new BlankNodeIssuer();

        return new ListToRdf(new ObjectToRdf($issuer, $strict, $rdfDirection), $issuer);
    }

    /**
     * @return list<mixed>
     */
    private static function items(string $json): array
    {
        /** @var list<mixed> $items */
        $items = json_decode($json, flags: JSON_THROW_ON_ERROR);

        return $items;
    }

    /**
     * @param list<Quad> $triples
     */
    private static function nquads(array $triples): string
    {
        $serializer = new Serializer();

        return implode('', array_map($serializer->serializeQuad(...), $triples));
    }
}
