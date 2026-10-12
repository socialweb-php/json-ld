<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Rdf;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Rdf\BlankNodeIssuer;
use SocialWeb\JsonLd\Rdf\ObjectToRdf;
use SocialWeb\JsonLd\RdfDirection;
use SocialWeb\Rdf\BlankNode;
use SocialWeb\Rdf\DefaultGraph;
use SocialWeb\Rdf\Iri;
use SocialWeb\Rdf\Literal;
use SocialWeb\Rdf\NQuads\Serializer;
use SocialWeb\Rdf\Quad;
use SocialWeb\Test\JsonLd\TestCase;

use function array_map;
use function implode;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class ObjectToRdfTest extends TestCase
{
    private const string XSD = 'http://www.w3.org/2001/XMLSchema#';

    private const string RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    #[DataProvider('valueObjects')]
    public function testConvertsAValueObjectToALiteral(
        string $item,
        string $lexicalForm,
        string $datatype,
        ?string $language = null,
    ): void {
        $triples = [];
        $term = self::objects()->convert(self::item($item), new DefaultGraph(), $triples);

        $this->assertInstanceOf(Literal::class, $term);
        $this->assertSame($lexicalForm, $term->lexicalForm);
        $this->assertSame($datatype, $term->datatype->value);
        $this->assertSame($language, $term->language);
        $this->assertSame([], $triples);
    }

    /**
     * @return iterable<string, array{string, string, string, 3?: string}>
     */
    public static function valueObjects(): iterable
    {
        yield 'a string' => ['{"@value": "x"}', 'x', self::XSD . 'string'];
        yield 'a string with a language, which the literal lowercases' => [
            '{"@value": "x", "@language": "en-US"}',
            'x',
            self::RDF . 'langString',
            'en-us',
        ];
        yield 'a string with a datatype' => [
            '{"@value": "2023-01-01", "@type": "' . self::XSD . 'date"}',
            '2023-01-01',
            self::XSD . 'date',
        ];
        yield 'true' => ['{"@value": true}', 'true', self::XSD . 'boolean'];
        yield 'false with a datatype keeps the datatype' => [
            '{"@value": false, "@type": "https://example.com/T"}',
            'false',
            'https://example.com/T',
        ];
        yield 'an integer' => ['{"@value": 42}', '42', self::XSD . 'integer'];
        yield 'a negative integer' => ['{"@value": -7}', '-7', self::XSD . 'integer'];
        yield 'a float with no fractional part is an integer' => ['{"@value": 5.0}', '5', self::XSD . 'integer'];
        yield 'negative zero is zero' => ['{"@value": -0.0}', '0', self::XSD . 'integer'];
        yield 'a float with a fractional part is a double' => ['{"@value": 1.5}', '1.5E0', self::XSD . 'double'];
        yield 'a float of ten to the twenty-first is a double' => ['{"@value": 1e21}', '1.0E21', self::XSD . 'double'];
        yield 'a small double' => ['{"@value": 2.5e-7}', '2.5E-7', self::XSD . 'double'];
        yield 'an integer typed as a double is written as one' => [
            '{"@value": 42, "@type": "' . self::XSD . 'double"}',
            '4.2E1',
            self::XSD . 'double',
        ];
        yield 'a fraction typed as an integer is written as a double and keeps the datatype' => [
            '{"@value": 1.5, "@type": "' . self::XSD . 'integer"}',
            '1.5E0',
            self::XSD . 'integer',
        ];
        yield 'a number with another datatype keeps it' => [
            '{"@value": 3, "@type": "https://example.com/T"}',
            '3',
            'https://example.com/T',
        ];
        yield 'a string typed as a double stays as written' => [
            '{"@value": "1.50", "@type": "' . self::XSD . 'double"}',
            '1.50',
            self::XSD . 'double',
        ];
        yield 'a JSON literal is canonical JSON' => [
            '{"@value": {"b": [1.0, true, null], "a": "x"}, "@type": "@json"}',
            '{"a":"x","b":[1,true,null]}',
            self::RDF . 'JSON',
        ];
        yield 'a JSON literal that is a string is quoted' => [
            '{"@value": "x", "@type": "@json"}',
            '"x"',
            self::RDF . 'JSON',
        ];
        yield 'a JSON literal that is null' => ['{"@value": null, "@type": "@json"}', 'null', self::RDF . 'JSON'];
        yield 'a direction is ignored without an rdfDirection option' => [
            '{"@value": "x", "@language": "de", "@direction": "rtl"}',
            'x',
            self::RDF . 'langString',
            'de',
        ];
    }

    public function testConvertsANodeObjectToItsIdentifier(): void
    {
        $triples = [];
        $objects = self::objects();

        $iri = $objects->convert(self::item('{"@id": "https://example.com/a"}'), new DefaultGraph(), $triples);
        $blankNode = $objects->convert(self::item('{"@id": "_:b0"}'), new DefaultGraph(), $triples);

        $this->assertTrue($iri?->equals(new Iri('https://example.com/a')));
        $this->assertTrue($blankNode?->equals(new BlankNode('b0')));
        $this->assertSame([], $triples);
    }

    public function testConvertsAListObjectToTheHeadOfACollection(): void
    {
        $triples = [];
        $head = self::objects()->convert(self::item('{"@list": [{"@value": 1}]}'), new DefaultGraph(), $triples);

        $this->assertTrue($head?->equals(new BlankNode('b0')));
        $this->assertCount(2, $triples);
    }

    public function testWritesADirectionAsAnI18nDatatype(): void
    {
        $triples = [];
        $objects = self::objects(RdfDirection::I18nDatatype);

        $withLanguage = $objects->convert(
            self::item('{"@value": "x", "@language": "en-US", "@direction": "rtl"}'),
            new DefaultGraph(),
            $triples,
        );
        $withoutLanguage = $objects->convert(
            self::item('{"@value": "y", "@direction": "ltr"}'),
            new DefaultGraph(),
            $triples,
        );

        $this->assertTrue(
            $withLanguage?->equals(new Literal('x', new Iri('https://www.w3.org/ns/i18n#en-us_rtl'))),
        );
        $this->assertTrue($withoutLanguage?->equals(new Literal('y', new Iri('https://www.w3.org/ns/i18n#_ltr'))));
        $this->assertSame([], $triples);
    }

    public function testWritesADirectionAsACompoundLiteral(): void
    {
        $triples = [];
        $graph = new Iri('ex:g');
        $objects = self::objects(RdfDirection::CompoundLiteral);

        $withLanguage = $objects->convert(
            self::item('{"@value": "x", "@language": "en-US", "@direction": "rtl"}'),
            $graph,
            $triples,
        );
        $withoutLanguage = $objects->convert(self::item('{"@value": "y", "@direction": "ltr"}'), $graph, $triples);

        $this->assertTrue($withLanguage?->equals(new BlankNode('b0')));
        $this->assertTrue($withoutLanguage?->equals(new BlankNode('b1')));
        $this->assertSame(
            <<<'NQUADS'
            _:b0 <http://www.w3.org/1999/02/22-rdf-syntax-ns#value> "x" <ex:g> .
            _:b0 <http://www.w3.org/1999/02/22-rdf-syntax-ns#language> "en-us" <ex:g> .
            _:b0 <http://www.w3.org/1999/02/22-rdf-syntax-ns#direction> "rtl" <ex:g> .
            _:b1 <http://www.w3.org/1999/02/22-rdf-syntax-ns#value> "y" <ex:g> .
            _:b1 <http://www.w3.org/1999/02/22-rdf-syntax-ns#direction> "ltr" <ex:g> .

            NQUADS,
            self::nquads($triples),
        );
    }

    #[DataProvider('itemsThatAreNotWellFormed')]
    public function testAnItemThatIsNotWellFormedIsNullInLenientModeAndRefusedInStrictMode(
        string $item,
        DataLossCondition $condition,
        string $detail,
    ): void {
        $triples = [];

        $this->assertNull(self::objects()->convert(self::item($item), new DefaultGraph(), $triples));
        $this->assertSame([], $triples);

        try {
            self::objects(strict: true)->convert(self::item($item), new DefaultGraph(), $triples);
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame($condition, $dataLoss->condition);
            $this->assertSame($detail, $dataLoss->detail);
        }
    }

    /**
     * @return iterable<string, array{string, DataLossCondition, string}>
     */
    public static function itemsThatAreNotWellFormed(): iterable
    {
        yield 'a relative identifier' => ['{"@id": "relative"}', DataLossCondition::RelativeObject, 'relative'];
        yield 'the empty identifier of a null @id' => ['{"@id": ""}', DataLossCondition::RelativeObject, ''];
        yield 'an identifier with a space' => [
            '{"@id": "https://example.com/a b"}',
            DataLossCondition::RelativeObject,
            'https://example.com/a b',
        ];
        yield 'a blank node identifier that is not well-formed' => [
            '{"@id": "_:a b"}',
            DataLossCondition::MalformedBlankNodeIdentifier,
            '_:a b',
        ];
        yield 'a datatype that is not an IRI' => [
            '{"@value": "x", "@type": "not an iri"}',
            DataLossCondition::MalformedDatatype,
            'not an iri',
        ];
        yield 'a datatype that is a blank node identifier' => [
            '{"@value": "x", "@type": "_:b0"}',
            DataLossCondition::MalformedDatatype,
            '_:b0',
        ];
        yield 'a language tag that is not well-formed' => [
            '{"@value": "x", "@language": "a b"}',
            DataLossCondition::MalformedLanguageTag,
            'a b',
        ];
    }

    public function testRefusesToDropADirectionInStrictMode(): void
    {
        $triples = [];

        try {
            self::objects(strict: true)->convert(
                self::item('{"@direction": "rtl", "@value": "x", "@language": "de"}'),
                new DefaultGraph(),
                $triples,
            );
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame(DataLossCondition::DroppedDirection, $dataLoss->condition);
            $this->assertSame('{"@direction":"rtl","@language":"de","@value":"x"}', $dataLoss->detail);
        }
    }

    #[DataProvider('itemsThatAreNotExpanded')]
    public function testRefusesAnItemThatIsNotInExpandedForm(string $item, string $message): void
    {
        $triples = [];

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage($message);

        self::objects()->convert(self::item($item), new DefaultGraph(), $triples);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function itemsThatAreNotExpanded(): iterable
    {
        yield 'a scalar' => ['1', 'An item of a node map must be a map'];
        yield 'a list whose value is not an array' => ['{"@list": 1}', 'The value of @list must be an array'];
        yield 'a node object without an identifier' => [
            '{"https://example.com/p": []}',
            'A node object in a node map must have an @id entry that is a string',
        ];
        yield 'a datatype that is not a string' => [
            '{"@value": "x", "@type": 1}',
            'The @type of a value object must be a string',
        ];
        yield 'a language that is not a string' => [
            '{"@value": "x", "@language": 1}',
            'The @language and @direction of a value object must be strings',
        ];
        yield 'a direction that is not a string' => [
            '{"@value": "x", "@direction": true}',
            'The @language and @direction of a value object must be strings',
        ];
        yield 'a language beside a datatype' => [
            '{"@value": "x", "@language": "en", "@type": "https://example.com/T"}',
            'Only a value object whose @value is a string and that has no @type may have @language or @direction',
        ];
        yield 'a direction on a number' => [
            '{"@value": 1, "@direction": "rtl"}',
            'Only a value object whose @value is a string and that has no @type may have @language or @direction',
        ];
        yield 'a null value' => [
            '{"@value": null}',
            'The @value of a value object must be a string, a number, or a boolean, unless its @type is @json',
        ];
        yield 'an array value' => [
            '{"@value": [1]}',
            'The @value of a value object must be a string, a number, or a boolean, unless its @type is @json',
        ];
    }

    public function testRefusesAStringValueThatIsNotValidUtf8(): void
    {
        $triples = [];

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('The @value of a value object must be valid UTF-8');

        self::objects()->convert((object) ['@value' => "\xff"], new DefaultGraph(), $triples);
    }

    private static function objects(?RdfDirection $rdfDirection = null, bool $strict = false): ObjectToRdf
    {
        return new ObjectToRdf(new BlankNodeIssuer(), $strict, $rdfDirection);
    }

    private static function item(string $json): mixed
    {
        return json_decode($json, flags: JSON_THROW_ON_ERROR);
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
