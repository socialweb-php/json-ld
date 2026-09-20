<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Expansion;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\ContextProcessor;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Expansion\Expander;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\ProcessingMode;
use SocialWeb\JsonLd\Rdf\JsonCanonicalizer;
use SocialWeb\Test\JsonLd\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Expected results are written as JSON and compared as canonical JSON, so the
 * order of map entries does not matter, and the order of arrays does.
 */
class ExpanderTest extends TestCase
{
    private const string BASE = 'https://example.com/dir/doc.jsonld';

    public function testNullExpandsToNull(): void
    {
        $this->assertExpandsTo('null', 'null');
    }

    #[DataProvider('expansions')]
    public function testExpands(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expansions(): iterable
    {
        yield 'a node with a property' => [
            '{"@id": "https://example.com/a", "https://example.com/name": [{"@value": "A"}]}',
            '{"@id": "https://example.com/a", "https://example.com/name": "A"}',
        ];
        yield 'an array is flattened and loses its nulls' => [
            '[{"ex:p": [{"@value": 1}]}, {"ex:p": [{"@value": 2}]}, {"ex:p": [{"@value": 3}]}]',
            '[{"ex:p": 1}, null, [{"ex:p": 2}, [{"ex:p": 3}]]]',
        ];
        yield 'values of every scalar kind' => [
            '{"ex:p": [{"@value": "text"}, {"@value": 1}, {"@value": 1.5}, {"@value": true}, {"@value": false}]}',
            '{"ex:p": ["text", 1, 1.5, true, false]}',
        ];
        yield 'a null value and a null item are left out' => [
            '{"ex:kept": [{"@value": 1}]}',
            '{"ex:kept": [null, 1], "ex:gone": null}',
        ];
        yield 'an empty array stays as an empty list' => ['{"ex:p": []}', '{"ex:p": []}'];
        yield 'an empty node is kept as the value of a property' => ['{"ex:p": [{}]}', '{"ex:p": {}}'];
        yield 'a node reference is kept as the value of a property' => [
            '{"ex:p": [{"@id": "ex:other"}]}',
            '{"ex:p": {"@id": "ex:other"}}',
        ];
        yield 'an embedded context' => [
            '{"https://example.com/ns#name": [{"@value": "A"}]}',
            '{"@context": {"@vocab": "https://example.com/ns#"}, "name": "A"}',
        ];
        yield 'an embedded context applies to the nodes below it' => [
            '{"https://example.com/ns#knows": [{"https://example.com/ns#name": [{"@value": "B"}]}]}',
            '{"@context": {"@vocab": "https://example.com/ns#"}, "knows": {"name": "B"}}',
        ];
        yield 'a term made of digits' => [
            '{"https://example.com/ns#123": [{"@value": "A"}]}',
            '{"@context": {"@vocab": "https://example.com/ns#"}, "123": "A"}',
        ];
        yield 'two keys for one property add up, in code point order of the keys' => [
            '{"https://example.com/ns#name": [{"@value": "first"}, {"@value": "second"}]}',
            '{"@context": {"a": "https://example.com/ns#name", "b": "https://example.com/ns#name"},'
                . ' "b": "second", "a": "first"}',
        ];
    }

    #[DataProvider('identifiers')]
    public function testExpandsIdentifiers(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function identifiers(): iterable
    {
        yield 'a relative @id is resolved against the base' => [
            '{"@id": "https://example.com/dir/other", "ex:p": [{"@value": 1}]}',
            '{"@id": "other", "ex:p": 1}',
        ];
        yield 'an @id is never a term' => [
            '{"@id": "https://example.com/dir/name", "ex:p": [{"@value": 1}]}',
            '{"@context": {"name": "https://example.com/ns#name"}, "@id": "name", "ex:p": 1}',
        ];
        yield 'a compact @id' => [
            '{"@id": "https://example.com/ns#a", "ex:p": [{"@value": 1}]}',
            '{"@context": {"ns": "https://example.com/ns#"}, "@id": "ns:a", "ex:p": 1}',
        ];
        yield 'an alias of @id' => [
            '{"@id": "https://example.com/a", "ex:p": [{"@value": 1}]}',
            '{"@context": {"id": "@id"}, "id": "https://example.com/a", "ex:p": 1}',
        ];
        yield 'a blank node identifier' => ['{"@id": "_:b0", "ex:p": [{"@value": 1}]}', '{"@id": "_:b0", "ex:p": 1}'];
    }

    #[DataProvider('types')]
    public function testExpandsTypes(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function types(): iterable
    {
        yield 'a string becomes an array of one' => [
            '{"@type": ["https://example.com/ns#Thing"]}',
            '{"@context": {"@vocab": "https://example.com/ns#"}, "@type": "Thing"}',
        ];
        yield 'an array keeps its order' => [
            '{"@type": ["https://example.com/ns#B", "https://example.com/ns#A"]}',
            '{"@context": {"@vocab": "https://example.com/ns#"}, "@type": ["B", "A"]}',
        ];
        yield 'an empty array' => ['{"@type": []}', '{"@type": []}'];
        yield 'a type that is not a term is relative to the base' => [
            '{"@type": ["https://example.com/dir/Thing"]}',
            '{"@type": "Thing"}',
        ];
        yield 'two keys that expand to @type add up, in code point order of the keys' => [
            '{"@type": ["ex:B", "ex:A"]}',
            '{"@context": {"kind": "@type"}, "kind": "ex:A", "@type": "ex:B"}',
        ];
        yield 'two keys that expand to @type add up when both are arrays' => [
            '{"@type": ["ex:B", "ex:C", "ex:A", "ex:D"]}',
            '{"@context": {"kind": "@type"}, "kind": ["ex:A", "ex:D"], "@type": ["ex:B", "ex:C"]}',
        ];
        yield 'the type of a value object stays a string' => [
            '{"ex:p": [{"@value": "2026-01-01", "@type": "http://www.w3.org/2001/XMLSchema#date"}]}',
            '{"ex:p": {"@value": "2026-01-01", "@type": "http://www.w3.org/2001/XMLSchema#date"}}',
        ];
    }

    #[DataProvider('scopedContexts')]
    public function testAppliesScopedContexts(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function scopedContexts(): iterable
    {
        yield 'a property-scoped context applies to a scalar value' => [
            '{"ex:p": [{"@value": "A", "@language": "en"}]}',
            '{"@context": {"p": {"@id": "ex:p", "@context": {"@language": "en"}}}, "p": "A"}',
        ];
        yield 'a property-scoped context applies to a map value' => [
            '{"ex:p": [{"ex:name": [{"@value": "A"}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@context": {"name": "ex:name"}}}, "p": {"name": "A"}}',
        ];
        yield 'a property-scoped context may redefine a protected term, for a scalar' => [
            '{"ex:p": [{"@id": "https://example.com/dir/A"}]}',
            '{"@context": {"@protected": true, "p": {"@id": "ex:p",'
                . ' "@context": {"p": {"@id": "ex:p", "@type": "@id"}}}}, "p": "A"}',
        ];
        yield 'a property-scoped context may redefine a protected term, for a map' => [
            '{"ex:p": [{"ex:q": [{"@value": "A"}]}]}',
            '{"@context": {"@protected": true, "name": "ex:name", "p": {"@id": "ex:p", "@context": {"name": "ex:q"}}},'
                . ' "p": {"name": "A"}}',
        ];
        yield 'a property-scoped context stays in effect further down' => [
            '{"ex:p": [{"ex:q": [{"ex:name": [{"@value": "A"}]}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@context": {"name": "ex:name", "q": "ex:q"}}},'
                . ' "p": {"q": {"name": "A"}}}',
        ];
        yield 'a type-scoped context applies to the node that has the type' => [
            '{"@type": ["ex:Person"], "ex:name": [{"@value": "A"}]}',
            '{"@context": {"Person": {"@id": "ex:Person", "@context": {"name": "ex:name"}}},'
                . ' "@type": "Person", "name": "A"}',
        ];
        yield 'a type-scoped context does not reach the nodes below' => [
            '{"@type": ["ex:Person"], "ex:knows": [{"ex:other": [{"@value": "B"}]}]}',
            '{"@context": {"@vocab": "ex:", "Person": {"@id": "ex:Person", "@context": {"name": "ex:scoped"}},'
                . ' "name": "ex:other"}, "@type": "Person", "knows": {"name": "B"}}',
        ];
        yield 'a type-scoped context still applies to a value object' => [
            '{"@type": ["ex:Person"], "ex:name": [{"@value": "A", "@type": "ex:scoped"}]}',
            '{"@context": {"@vocab": "ex:", "Person": {"@context": {"t": "ex:scoped"}}},'
                . ' "@type": "Person", "name": {"@value": "A", "@type": "t"}}',
        ];
        yield 'a type-scoped context still applies to a scalar' => [
            '{"@type": ["ex:Person"], "ex:name": [{"@value": "A", "@language": "en"}]}',
            '{"@context": {"@vocab": "ex:", "Person": {"@context": {"@language": "en"}}},'
                . ' "@type": "Person", "name": "A"}',
        ];
        yield 'a type-scoped context still applies to a node reference' => [
            '{"@type": ["ex:Person"], "ex:knows": [{"@id": "https://example.org/scoped/b"}]}',
            '{"@context": {"@vocab": "ex:", "Person": {"@context": {"@base": "https://example.org/scoped/"}}},'
                . ' "@type": "Person", "knows": {"@id": "b"}}',
        ];
        yield 'a node reference with a second entry is a new node' => [
            '{"@type": ["ex:Person"],'
                . ' "ex:knows": [{"@id": "https://example.com/dir/b", "ex:name": [{"@value": "B"}]}]}',
            '{"@context": {"@vocab": "ex:", "Person": {"@context": {"@base": "https://example.org/scoped/"}}},'
                . ' "@type": "Person", "knows": {"@id": "b", "name": "B"}}',
        ];
        yield 'type-scoped contexts are applied in code point order of the types' => [
            '{"@type": ["ex:B", "ex:A"], "ex:fromB": [{"@value": 1}]}',
            '{"@context": {"A": {"@id": "ex:A", "@context": {"p": "ex:fromA"}},'
                . ' "B": {"@id": "ex:B", "@context": {"p": "ex:fromB"}}}, "@type": ["B", "A"], "p": 1}',
        ];
        yield 'types are expanded with the context from before the type-scoped contexts' => [
            '{"@type": ["ex:A", "ex:B"]}',
            '{"@context": {"A": {"@id": "ex:A", "@context": {"B": "ex:scopedB"}}, "B": "ex:B"}, "@type": ["A", "B"]}',
        ];
        yield 'a type-scoped context that propagates reaches the nodes below' => [
            '{"@type": ["ex:Person"], "ex:knows": [{"ex:scoped": [{"@value": "B"}]}]}',
            '{"@context": {"@vocab": "ex:", "Person": {"@context": {"@propagate": true, "name": "ex:scoped"}}},'
                . ' "@type": "Person", "knows": {"name": "B"}}',
        ];
        yield 'an embedded context that does not propagate is left behind further down' => [
            '{"ex:knows": [{"ex:scoped": [{"@value": "A"}], "ex:knows": [{"ex:name": [{"@value": "B"}]}]}]}',
            '{"@context": {"@vocab": "ex:"}, "knows": {"@context": {"@propagate": false, "name": "ex:scoped"},'
                . ' "name": "A", "knows": {"name": "B"}}}',
        ];
    }

    #[DataProvider('valueObjects')]
    public function testExpandsValueObjects(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valueObjects(): iterable
    {
        yield 'a language tag is lowercased' => [
            '{"ex:p": [{"@value": "A", "@language": "en-us"}]}',
            '{"ex:p": {"@value": "A", "@language": "en-US"}}',
        ];
        yield 'a direction and an index' => [
            '{"ex:p": [{"@value": "A", "@direction": "rtl", "@index": "one"}]}',
            '{"ex:p": {"@value": "A", "@direction": "rtl", "@index": "one"}}',
        ];
        yield 'aliases of the keywords of a value object' => [
            '{"ex:p": [{"@value": "A", "@language": "en"}]}',
            '{"@context": {"value": "@value", "lang": "@language"}, "ex:p": {"value": "A", "lang": "en"}}',
        ];
        yield 'a JSON literal keeps its value as it is' => [
            '{"ex:p": [{"@value": {"b": [2, 1], "a": null}, "@type": "@json"}]}',
            '{"ex:p": {"@value": {"b": [2, 1], "a": null}, "@type": "@json"}}',
        ];
        yield 'a JSON literal may be null' => [
            '{"ex:p": [{"@value": null, "@type": "@json"}]}',
            '{"ex:p": {"@value": null, "@type": "@json"}}',
        ];
        yield 'a JSON literal through an alias of @type, which sorts after @value' => [
            '{"ex:p": [{"@value": [1], "@type": "@json"}]}',
            '{"@context": {"type": "@type"}, "ex:p": {"@value": [1], "type": "@json"}}',
        ];
        yield 'a JSON literal through @type itself, where an alias of @type is also defined' => [
            '{"ex:p": [{"@value": [1], "@type": "@json"}]}',
            '{"@context": {"type": "@type"}, "ex:p": {"@value": [1], "@type": "@json"}}',
        ];
        yield 'a term whose type is @json keeps any value as it is' => [
            '{"ex:p": [{"@value": {"@id": "not expanded"}, "@type": "@json"}]}',
            '{"@context": {"p": {"@id": "ex:p", "@type": "@json"}}, "p": {"@id": "not expanded"}}',
        ];
        yield 'a term whose type is @json keeps an array as one value' => [
            '{"ex:p": [{"@value": [1, null], "@type": "@json"}]}',
            '{"@context": {"p": {"@id": "ex:p", "@type": "@json"}}, "p": [1, null]}',
        ];
        yield 'a type-scoped context that turns the type into an alias of @json' => [
            '{"ex:p": [{"@value": [1], "@type": "ex:X"}]}',
            '{"@context": {"X": {"@id": "ex:X", "@context": {"X": "@json"}}},'
                . ' "ex:p": {"@value": [1], "@type": "X"}}',
        ];
    }

    #[DataProvider('listsAndSets')]
    public function testExpandsListsAndSets(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function listsAndSets(): iterable
    {
        yield 'a list object' => [
            '{"ex:p": [{"@list": [{"@value": 1}, {"@value": 2}]}]}',
            '{"ex:p": {"@list": [1, 2]}}',
        ];
        yield 'a list of one, not in an array' => ['{"ex:p": [{"@list": [{"@value": 1}]}]}', '{"ex:p": {"@list": 1}}'];
        yield 'a list of null is empty' => ['{"ex:p": [{"@list": []}]}', '{"ex:p": {"@list": null}}'];
        yield 'a list with an index' => [
            '{"ex:p": [{"@list": [], "@index": "one"}]}',
            '{"ex:p": {"@list": [], "@index": "one"}}',
        ];
        yield 'a list container' => [
            '{"ex:p": [{"@list": [{"@value": 1}, {"@value": 2}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@list"}}, "p": [1, 2]}',
        ];
        yield 'a list container with one value, not in an array' => [
            '{"ex:p": [{"@list": [{"@value": 1}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@list"}}, "p": 1}',
        ];
        yield 'a list container leaves a list object alone' => [
            '{"ex:p": [{"@list": [{"@value": 1}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@list"}}, "p": {"@list": [1]}}',
        ];
        yield 'a list container makes lists of the arrays inside' => [
            '{"ex:p": [{"@list": [{"@list": [{"@value": 1}]}, {"@list": []}, {"@value": 2}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@list"}}, "p": [[1], [], 2]}',
        ];
        yield 'a set object is unwrapped' => [
            '{"ex:p": [{"@value": 1}, {"@value": 2}]}',
            '{"ex:p": {"@set": [1, 2]}}',
        ];
        yield 'a set object of one value, with an index' => [
            '{"ex:p": [{"@value": 1}]}',
            '{"ex:p": {"@set": 1, "@index": "ignored"}}',
        ];
        yield 'a set of null comes to nothing' => ['{"ex:q": [{"@value": 1}]}', '{"ex:p": {"@set": null}, "ex:q": 1}'];
        yield 'a set container changes nothing' => [
            '{"ex:p": [{"@value": 1}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@set"}}, "p": 1}',
        ];
    }

    #[DataProvider('graphsAndIncludedBlocks')]
    public function testExpandsGraphsAndIncludedBlocks(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function graphsAndIncludedBlocks(): iterable
    {
        yield 'the value of @graph is always an array' => [
            '{"@id": "ex:g", "@graph": [{"ex:p": [{"@value": 1}]}]}',
            '{"@id": "ex:g", "@graph": {"ex:p": 1}}',
        ];
        yield 'a graph of null is empty' => ['{"@id": "ex:g", "@graph": []}', '{"@id": "ex:g", "@graph": null}'];
        yield 'a graph container' => [
            '{"ex:p": [{"@graph": [{"ex:q": [{"@value": 1}]}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@graph"}}, "p": {"ex:q": 1}}',
        ];
        yield 'a graph container makes a graph of each value' => [
            '{"ex:p": [{"@graph": [{"ex:q": [{"@value": 1}]}]}, {"@graph": [{"ex:q": [{"@value": 2}]}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@graph"}}, "p": [{"ex:q": 1}, {"ex:q": 2}]}',
        ];
        yield 'a graph container wraps a graph object again' => [
            '{"ex:p": [{"@graph": [{"@graph": [{"ex:q": [{"@value": 1}]}]}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@graph"}}, "p": {"@graph": {"ex:q": 1}}}',
        ];
        yield 'an included block' => [
            '{"ex:p": [{"@value": 1}], "@included": [{"@id": "ex:a", "ex:q": [{"@value": 2}]}]}',
            '{"ex:p": 1, "@included": {"@id": "ex:a", "ex:q": 2}}',
        ];
        yield 'two keys that expand to @included add up, in code point order of the keys' => [
            '{"@included": [{"ex:q": [{"@value": 2}]}, {"ex:q": [{"@value": 3}]}, {"ex:q": [{"@value": 1}]},'
                . ' {"ex:q": [{"@value": 4}]}]}',
            '{"@context": {"included": "@included"}, "included": [{"ex:q": 1}, {"ex:q": 4}],'
                . ' "@included": [{"ex:q": 2}, {"ex:q": 3}]}',
        ];
    }

    #[DataProvider('reverseProperties')]
    public function testExpandsReverseProperties(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reverseProperties(): iterable
    {
        yield 'a reverse map' => [
            '{"@id": "ex:a", "@reverse": {"ex:parent": [{"@id": "ex:b"}, {"@id": "ex:c"}]}}',
            '{"@id": "ex:a", "@reverse": {"ex:parent": [{"@id": "ex:b"}, {"@id": "ex:c"}]}}',
        ];
        yield 'a string in a reverse map, under a term whose values are IRIs' => [
            '{"@id": "ex:a", "@reverse": {"ex:parent": [{"@id": "ex:b"}]}}',
            '{"@context": {"parent": {"@id": "ex:parent", "@type": "@id"}}, "@id": "ex:a",'
                . ' "@reverse": {"parent": "ex:b"}}',
        ];
        yield 'a reverse property' => [
            '{"@id": "ex:a", "@reverse": {"ex:parent": [{"@id": "ex:b"}, {"@id": "ex:c"}]}}',
            '{"@context": {"children": {"@reverse": "ex:parent", "@type": "@id"}}, "@id": "ex:a",'
                . ' "children": ["ex:b", "ex:c"]}',
        ];
        yield 'a reverse property and a reverse map share one map' => [
            '{"@id": "ex:a", "@reverse": {"ex:parent": [{"@id": "ex:c"}, {"@id": "ex:b"}], "ex:q": [{"@id": "ex:d"}]}}',
            '{"@context": {"children": {"@reverse": "ex:parent", "@type": "@id"}}, "@id": "ex:a",'
                . ' "children": "ex:b", "@reverse": {"ex:parent": {"@id": "ex:c"}, "ex:q": {"@id": "ex:d"}}}',
        ];
        yield 'a reverse property inside a reverse map is a property' => [
            '{"@id": "ex:a", "ex:parent": [{"@id": "ex:b"}]}',
            '{"@context": {"children": {"@reverse": "ex:parent", "@type": "@id"}}, "@id": "ex:a",'
                . ' "@reverse": {"children": "ex:b"}}',
        ];
        yield 'a reverse map with a reverse property and a property' => [
            '{"@id": "ex:a", "ex:parent": [{"@id": "ex:b"}], "@reverse": {"ex:q": [{"@id": "ex:d"}]}}',
            '{"@context": {"children": {"@reverse": "ex:parent", "@type": "@id"}}, "@id": "ex:a",'
                . ' "@reverse": {"children": "ex:b", "ex:q": {"@id": "ex:d"}}}',
        ];
        yield 'a property from a reverse map joins the same property given directly' => [
            '{"@id": "ex:a", "ex:parent": [{"@id": "ex:b"}, {"@id": "ex:c"}]}',
            '{"@context": {"children": {"@reverse": "ex:parent", "@type": "@id"}}, "@id": "ex:a",'
                . ' "@reverse": {"children": "ex:b"}, "ex:parent": {"@id": "ex:c"}}',
        ];
    }

    #[DataProvider('nestedProperties')]
    public function testExpandsNestedProperties(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nestedProperties(): iterable
    {
        yield 'the entries under @nest belong to the node' => [
            '{"ex:p": [{"@value": 1}], "ex:q": [{"@value": 2}]}',
            '{"ex:p": 1, "@nest": {"ex:q": 2}}',
        ];
        yield 'an array under @nest' => [
            '{"ex:p": [{"@value": 1}, {"@value": 2}]}',
            '{"@nest": [{"ex:p": 1}, {"ex:p": 2}]}',
        ];
        yield 'an alias of @nest, and @nest inside it' => [
            '{"ex:p": [{"@value": 1}], "ex:q": [{"@value": 2}]}',
            '{"@context": {"details": "@nest"}, "details": {"ex:p": 1, "@nest": {"ex:q": 2}}}',
        ];
        yield 'a property is added after the properties beside its @nest' => [
            '{"ex:p": [{"@value": "beside"}, {"@value": "nested"}]}',
            '{"@nest": {"ex:p": "nested"}, "ex:p": "beside"}',
        ];
        yield 'an alias of @nest with a scoped context' => [
            '{"ex:scoped": [{"@value": 1}], "ex:p": [{"@value": 2}]}',
            '{"@context": {"p": "ex:p", "details": {"@id": "@nest", "@context": {"p": "ex:scoped"}}},'
                . ' "details": {"p": 1}, "p": 2}',
        ];
        yield 'the entries under @nest are read in code point order' => [
            '{"ex:p": [{"@value": 1}, {"@value": 2}]}',
            '{"@context": {"a": "ex:p", "b": "ex:p"}, "@nest": {"b": 2, "a": 1}}',
        ];
        yield 'the scoped context of an alias of @nest may redefine a protected term' => [
            '{"ex:scoped": [{"@value": 1}]}',
            '{"@context": {"@protected": true, "p": "ex:p",'
                . ' "details": {"@id": "@nest", "@context": {"p": "ex:scoped"}}}, "details": {"p": 1}}',
        ];
        yield 'a keyword under @nest' => [
            '{"@id": "ex:a", "ex:p": [{"@value": 1}]}',
            '{"@nest": {"@id": "ex:a"}, "ex:p": 1}',
        ];
    }

    #[DataProvider('languageMaps')]
    public function testExpandsLanguageMaps(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function languageMaps(): iterable
    {
        $context = '"@context": {"label": {"@id": "ex:label", "@container": "@language"}, "none": "@none"}';

        yield 'languages in code point order, tags lowercased, nulls left out' => [
            '{"ex:label": [{"@value": "Hallo", "@language": "de"}, {"@value": "Hello", "@language": "en-gb"},'
                . ' {"@value": "Hi", "@language": "en-gb"}]}',
            '{' . $context . ', "label": {"en-GB": ["Hello", null, "Hi"], "de": "Hallo", "fr": null}}',
        ];
        yield '@none and an alias of it give a plain string' => [
            '{"ex:label": [{"@value": "A"}, {"@value": "B"}]}',
            '{' . $context . ', "label": {"@none": "A", "none": "B"}}',
        ];
        yield 'the default base direction applies' => [
            '{"ex:label": [{"@value": "A", "@language": "ar", "@direction": "rtl"}]}',
            '{"@context": {"@direction": "rtl", "label": {"@id": "ex:label", "@container": "@language"}},'
                . ' "label": {"ar": "A"}}',
        ];
        yield 'the direction of the term comes before the default' => [
            '{"ex:label": [{"@value": "A", "@language": "en", "@direction": "ltr"}]}',
            '{"@context": {"@direction": "rtl", "label": {"@id": "ex:label", "@container": "@language",'
                . ' "@direction": "ltr"}}, "label": {"en": "A"}}',
        ];
        yield 'a term with a direction of null has none' => [
            '{"ex:label": [{"@value": "A", "@language": "en"}]}',
            '{"@context": {"@direction": "rtl", "label": {"@id": "ex:label", "@container": "@language",'
                . ' "@direction": null}}, "label": {"en": "A"}}',
        ];
        yield 'a value that is not a map is expanded as usual' => [
            '{"ex:label": [{"@value": "A"}]}',
            '{' . $context . ', "label": "A"}',
        ];
        yield 'a language made of digits' => [
            '{"ex:label": [{"@value": "A", "@language": "123"}]}',
            '{' . $context . ', "label": {"123": "A"}}',
        ];
    }

    #[DataProvider('indexMaps')]
    public function testExpandsIndexTypeAndIdentifierMaps(string $expected, string $document): void
    {
        $this->assertExpandsTo($expected, $document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function indexMaps(): iterable
    {
        yield 'an index map, in code point order of the indexes' => [
            '{"ex:p": [{"@value": 1, "@index": "a"}, {"@value": 2, "@index": "b"}, {"@value": 3, "@index": "b"}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@index"}}, "p": {"b": [2, 3], "a": 1}}',
        ];
        yield 'an item keeps an index of its own' => [
            '{"ex:p": [{"@value": 1, "@index": "own"}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@index"}}, "p": {"a": {"@value": 1, "@index": "own"}}}',
        ];
        yield 'an index of @none, or an alias of it, adds nothing' => [
            '{"ex:p": [{"@value": 1}, {"@value": 2}, {"@value": 3}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@index"}, "none": "@none"},'
                . ' "p": {"@none": [1, 2], "none": 3}}',
        ];
        yield 'an index made of digits stays a string' => [
            '{"ex:p": [{"@value": 1, "@index": "123"}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@index"}}, "p": {"123": 1}}',
        ];
        yield 'an index that becomes a property' => [
            '{"ex:p": [{"@id": "ex:a", "ex:key": [{"@value": "one"}, {"@value": "own"}]}]}',
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@index", "@index": "key"}},'
                . ' "p": {"one": {"@id": "ex:a", "key": "own"}}}',
        ];
        yield 'an index that becomes a property follows the type of that property' => [
            '{"ex:p": [{"@id": "ex:a", "ex:key": [{"@id": "https://example.com/dir/one"}]}]}',
            '{"@context": {"@vocab": "ex:", "key": {"@type": "@id"}, "p": {"@container": "@index", "@index": "key"}},'
                . ' "p": {"one": {"@id": "ex:a"}}}',
        ];
        yield 'an index of @none does not become a property' => [
            '{"ex:p": [{"@id": "ex:a"}]}',
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@index", "@index": "key"}},'
                . ' "p": {"@none": {"@id": "ex:a"}}}',
        ];
        yield 'an identifier map' => [
            '{"ex:p": [{"@id": "https://example.com/dir/a", "ex:q": [{"@value": 1}]},'
                . ' {"@id": "ex:own", "ex:q": [{"@value": 2}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@id"}},'
                . ' "p": {"a": {"ex:q": 1}, "b": {"@id": "ex:own", "ex:q": 2}}}',
        ];
        yield 'an identifier of @none adds nothing' => [
            '{"ex:p": [{"ex:q": [{"@value": 1}]}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@id"}}, "p": {"@none": {"ex:q": 1}}}',
        ];
        yield 'a type map puts its type first' => [
            '{"ex:p": [{"@type": ["ex:A", "ex:Own"], "ex:q": [{"@value": 1}]}, {"@type": ["ex:B"]}]}',
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@type"}},'
                . ' "p": {"A": {"@type": "Own", "q": 1}, "B": {}}}',
        ];
        yield 'a type of @none adds nothing' => [
            '{"ex:p": [{"ex:q": [{"@value": 1}]}]}',
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@type"}}, "p": {"@none": {"q": 1}}}',
        ];
        yield 'a type map applies the scoped context of its type' => [
            '{"ex:p": [{"@type": ["ex:A"], "ex:scoped": [{"@value": 1}]}]}',
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@type"}, "A": {"@context": {"q": "ex:scoped"}}},'
                . ' "p": {"A": {"q": 1}}}',
        ];
        yield 'a type map does not propagate the scoped context of its type' => [
            '{"ex:p": [{"@type": ["ex:T"], "ex:scoped": [{"@value": "A"}],'
                . ' "ex:other": [{"ex:name": [{"@value": "B"}]}]}]}',
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@type"}, "T": {"@context": {"name": "ex:scoped"}}},'
                . ' "p": {"T": {"name": "A", "other": {"name": "B"}}}}',
        ];
        yield 'the same document written with @type does not propagate either' => [
            '{"@type": ["ex:T"], "ex:scoped": [{"@value": "A"}], "ex:other": [{"ex:name": [{"@value": "B"}]}]}',
            '{"@context": {"@vocab": "ex:", "T": {"@context": {"name": "ex:scoped"}}},'
                . ' "@type": "T", "name": "A", "other": {"name": "B"}}',
        ];
        yield 'a type map is read with the context from before a type-scoped one' => [
            '{"@type": ["ex:Outer"], "ex:p": [{"@type": ["ex:A"], "ex:q": [{"@value": 1}]}]}',
            '{"@context": {"@vocab": "ex:", "Outer": {"@context": {"q": "ex:scoped"}}, "p": {"@container": "@type"}},'
                . ' "@type": "Outer", "p": {"A": {"q": 1}}}',
        ];
        yield 'a string in a type map, under a term whose values are IRIs' => [
            '{"ex:p": [{"@id": "https://example.com/dir/a", "@type": ["ex:A"]}]}',
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@type", "@type": "@id"}}, "p": {"A": "a"}}',
        ];
        yield 'a graph container with an index' => [
            '{"ex:p": [{"@graph": [{"ex:q": [{"@value": 1}]}], "@index": "a"}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": ["@graph", "@index"]}}, "p": {"a": {"ex:q": 1}}}',
        ];
        yield 'a graph container with an identifier leaves a graph object as it is' => [
            '{"ex:p": [{"@graph": [{"ex:q": [{"@value": 1}]}], "@id": "https://example.com/dir/g"}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": ["@graph", "@id"]}},'
                . ' "p": {"g": {"@graph": {"ex:q": 1}}}}',
        ];
    }

    public function testTheOutputDoesNotDependOnTheOrderOfTheInput(): void
    {
        $one = '{"@context": {"@vocab": "ex:"}, "@id": "ex:a", "b": 1, "a": {"d": 2, "c": 3}, "@type": "T"}';
        $two = '{"@type": "T", "a": {"c": 3, "d": 2}, "b": 1, "@id": "ex:a", "@context": {"@vocab": "ex:"}}';

        $this->assertSame(json_encode(self::expand($one)), json_encode(self::expand($two)));
        $this->assertSame(
            '{"@id":"ex:a","@type":["ex:T"],"ex:a":[{"ex:c":[{"@value":3}],"ex:d":[{"@value":2}]}],'
                . '"ex:b":[{"@value":1}]}',
            json_encode(self::expand($one)),
        );
    }

    #[DataProvider('errors')]
    public function testRejects(
        ErrorCode $expected,
        string $document,
        ProcessingMode $mode = ProcessingMode::JsonLd11,
    ): void {
        foreach ([true, false] as $strict) {
            try {
                self::expand($document, $strict, $mode);
                $this->fail('Expected a JsonLdError with the code "' . $expected->value . '"');
            } catch (JsonLdError $error) {
                $this->assertSame($expected, $error->errorCode, $error->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{0: ErrorCode, 1: string, 2?: ProcessingMode}>
     */
    public static function errors(): iterable
    {
        yield 'a keyword in a reverse map' => [
            ErrorCode::InvalidReversePropertyMap,
            '{"@reverse": {"@id": "ex:a"}}',
        ];
        yield '@nest in a reverse map' => [
            ErrorCode::InvalidReversePropertyMap,
            '{"@reverse": {"@nest": {"ex:p": {"@id": "ex:a"}}}}',
        ];
        yield 'a keyword and its alias' => [
            ErrorCode::CollidingKeywords,
            '{"@context": {"id": "@id"}, "@id": "ex:a", "id": "ex:b"}',
        ];
        yield '@reverse and its alias' => [
            ErrorCode::CollidingKeywords,
            '{"@context": {"rev": "@reverse"}, "@reverse": {"ex:p": {"@id": "ex:a"}},'
                . ' "rev": {"ex:p": {"@id": "ex:b"}}}',
        ];
        yield 'a reverse property and then an alias of @reverse' => [
            ErrorCode::CollidingKeywords,
            '{"@context": {"children": {"@reverse": "ex:parent"}, "rev": "@reverse"},'
                . ' "children": {"@id": "ex:a"}, "rev": {"ex:p": {"@id": "ex:b"}}}',
        ];
        yield '@type twice, in JSON-LD 1.0' => [
            ErrorCode::CollidingKeywords,
            '{"@context": {"kind": "@type"}, "@type": "ex:A", "kind": "ex:B"}',
            ProcessingMode::JsonLd10,
        ];
        yield '@id is not a string' => [ErrorCode::InvalidIdValue, '{"@id": 1}'];
        yield '@id is null' => [ErrorCode::InvalidIdValue, '{"@id": null}'];
        yield '@type is a number' => [ErrorCode::InvalidTypeValue, '{"@type": 1}'];
        yield '@type is a map' => [ErrorCode::InvalidTypeValue, '{"@type": {}}'];
        yield '@type holds a number' => [ErrorCode::InvalidTypeValue, '{"@type": ["ex:A", 1]}'];
        yield '@value is a map' => [ErrorCode::InvalidValueObjectValue, '{"ex:p": {"@value": {}}}'];
        yield '@value is an array' => [ErrorCode::InvalidValueObjectValue, '{"ex:p": {"@value": [1]}}'];
        yield 'a JSON literal in JSON-LD 1.0' => [
            ErrorCode::InvalidValueObjectValue,
            '{"ex:p": {"@value": [1], "@type": "@json"}}',
            ProcessingMode::JsonLd10,
        ];
        yield '@language is not a string' => [
            ErrorCode::InvalidLanguageTaggedString,
            '{"ex:p": {"@value": "A", "@language": 1}}',
        ];
        yield '@direction is not ltr or rtl' => [
            ErrorCode::InvalidBaseDirection,
            '{"ex:p": {"@value": "A", "@direction": "up"}}',
        ];
        yield '@index is not a string' => [ErrorCode::InvalidIndexValue, '{"ex:p": {"@value": "A", "@index": 1}}'];
        yield '@reverse is not a map' => [ErrorCode::InvalidReverseValue, '{"@reverse": "ex:a"}'];
        yield 'a value object in a reverse map' => [
            ErrorCode::InvalidReversePropertyValue,
            '{"@reverse": {"ex:p": [{"@id": "ex:a"}, "text"]}}',
        ];
        yield 'a list object in a reverse map' => [
            ErrorCode::InvalidReversePropertyValue,
            '{"@reverse": {"ex:p": {"@list": []}}}',
        ];
        yield 'a value object under a reverse property' => [
            ErrorCode::InvalidReversePropertyValue,
            '{"@context": {"children": {"@reverse": "ex:parent"}}, "children": [{"@id": "ex:a"}, "text"]}',
        ];
        yield 'a list object under a reverse property' => [
            ErrorCode::InvalidReversePropertyValue,
            '{"@context": {"children": {"@reverse": "ex:parent"}}, "children": {"@list": []}}',
        ];
        yield '@nest is not a map' => [ErrorCode::InvalidNestValue, '{"@nest": "text"}'];
        yield '@nest is null' => [ErrorCode::InvalidNestValue, '{"@nest": null}'];
        yield '@nest holds something that is not a map' => [ErrorCode::InvalidNestValue, '{"@nest": [{"ex:p": 1}, 1]}'];
        yield '@nest holds @value' => [ErrorCode::InvalidNestValue, '{"@nest": {"@value": 1}}'];
        yield '@nest holds an alias of @value' => [
            ErrorCode::InvalidNestValue,
            '{"@context": {"value": "@value"}, "@nest": {"ex:p": 1, "value": 1}}',
        ];
        yield 'a language map value that is not a string' => [
            ErrorCode::InvalidLanguageMapValue,
            '{"@context": {"label": {"@id": "ex:label", "@container": "@language"}}, "label": {"en": ["A", 1]}}',
        ];
        yield 'an index that becomes a property of a value object' => [
            ErrorCode::InvalidValueObject,
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@index", "@index": "key"}}, "p": {"one": "text"}}',
        ];
        yield 'a value object with another entry' => [
            ErrorCode::InvalidValueObject,
            '{"ex:p": {"@value": 1, "@id": "ex:a"}}',
        ];
        yield 'a value object with a property' => [ErrorCode::InvalidValueObject, '{"ex:p": {"@value": 1, "ex:q": 1}}'];
        yield 'a value object with a type and a language' => [
            ErrorCode::InvalidValueObject,
            '{"ex:p": {"@value": "A", "@type": "ex:t", "@language": "en"}}',
        ];
        yield 'a value object with a type and a direction' => [
            ErrorCode::InvalidValueObject,
            '{"ex:p": {"@value": "A", "@type": "ex:t", "@direction": "ltr"}}',
        ];
        yield 'a number with a language' => [
            ErrorCode::InvalidLanguageTaggedValue,
            '{"ex:p": {"@value": 1, "@language": "en"}}',
        ];
        yield 'a datatype that is a blank node identifier' => [
            ErrorCode::InvalidTypedValue,
            '{"ex:p": {"@value": 1, "@type": "_:b0"}}',
        ];
        yield 'a datatype that holds a space' => [
            ErrorCode::InvalidTypedValue,
            '{"ex:p": {"@value": 1, "@type": "https://example.com/a b"}}',
        ];
        yield 'a datatype that is an array' => [
            ErrorCode::InvalidTypedValue,
            '{"ex:p": {"@value": 1, "@type": ["ex:t"]}}',
        ];
        yield 'the first key that expands to @type decides whether the value is a JSON literal' => [
            ErrorCode::InvalidTypedValue,
            '{"@context": {"type": "@type"}, "ex:p": {"@value": [1], "@type": "@json", "type": "ex:t"}}',
        ];
        yield 'a type-scoped context that takes away an alias of @json' => [
            ErrorCode::InvalidValueObjectValue,
            '{"@context": {"X": {"@id": "@json", "@context": {"X": "ex:X"}}},'
                . ' "ex:p": {"@value": [1], "@type": "X"}}',
        ];
        yield 'a set object with a property' => [
            ErrorCode::InvalidSetOrListObject,
            '{"ex:p": {"@set": [], "ex:q": 1}}',
        ];
        yield 'a list object with an identifier' => [
            ErrorCode::InvalidSetOrListObject,
            '{"ex:p": {"@list": [], "@id": "ex:a"}}',
        ];
        yield 'a list object with an index and an identifier' => [
            ErrorCode::InvalidSetOrListObject,
            '{"ex:p": {"@list": [], "@index": "a", "@id": "ex:a"}}',
        ];
        yield 'a list object with types' => [
            ErrorCode::InvalidSetOrListObject,
            '{"ex:p": {"@list": [], "@type": ["ex:A"]}}',
        ];
        yield 'a context that is not valid' => [ErrorCode::InvalidLocalContext, '{"@context": 1, "ex:p": 1}'];
    }

    public function testADatatypeWithoutABaseIsNotAnIri(): void
    {
        $this->expectExceptionObject(new JsonLdError(ErrorCode::InvalidTypedValue));

        self::expand('{"ex:p": {"@value": 1, "@type": "relative"}}', base: null);
    }

    /**
     * The datatype is a value of `@type`, so strict mode meets the loss
     * before the value object is checked.
     */
    public function testADatatypeThatHasTheFormOfAKeyword(): void
    {
        $document = '{"ex:p": {"@value": 1, "@type": "@ignoreMe"}}';

        try {
            self::expand($document, strict: false);
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $error) {
            $this->assertSame(ErrorCode::InvalidTypedValue, $error->errorCode, $error->getMessage());
        }

        try {
            self::expand($document);
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame(DataLossCondition::ReservedTerm, $dataLoss->condition);
            $this->assertSame('@ignoreMe', $dataLoss->detail);
        }
    }

    public function testJsonLd10DropsAnIncludedBlockAndRefusesItInStrictMode(): void
    {
        $document = '{"ex:p": 1, "@included": {"ex:q": 2}}';

        $this->assertExpandsTo('{"ex:p": [{"@value": 1}]}', $document, strict: false, mode: ProcessingMode::JsonLd10);

        try {
            self::expand($document, mode: ProcessingMode::JsonLd10);
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame(DataLossCondition::UndefinedProperty, $dataLoss->condition);
            $this->assertSame('@included', $dataLoss->detail);
        }
    }

    public function testJsonLd10DropsADirectionAndRefusesItInStrictMode(): void
    {
        $document = '{"ex:p": {"@value": "A", "@direction": "rtl"}}';

        $this->assertExpandsTo('{"ex:p": [{"@value": "A"}]}', $document, strict: false, mode: ProcessingMode::JsonLd10);

        try {
            self::expand($document, mode: ProcessingMode::JsonLd10);
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame(DataLossCondition::UndefinedProperty, $dataLoss->condition);
            $this->assertSame('@direction', $dataLoss->detail);
        }
    }

    #[DataProvider('drops')]
    public function testDropsInLenientModeAndRefusesInStrictMode(
        string $expected,
        DataLossCondition $condition,
        string $detail,
        string $document,
    ): void {
        $this->assertExpandsTo($expected, $document, strict: false);

        try {
            self::expand($document);
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame($condition, $dataLoss->condition);
            $this->assertSame($detail, $dataLoss->detail);
        }
    }

    /**
     * @return iterable<string, array{string, DataLossCondition, string, string}>
     */
    public static function drops(): iterable
    {
        yield 'a property that is not defined' => [
            '{"ex:p": [{"@value": 1}]}',
            DataLossCondition::UndefinedProperty,
            'name',
            '{"ex:p": 1, "name": "A"}',
        ];
        yield 'a property that is defined as null' => [
            '{"ex:p": [{"@value": 1}]}',
            DataLossCondition::UndefinedProperty,
            'name',
            '{"@context": {"@vocab": "ex:", "name": null}, "p": 1, "name": "A"}',
        ];
        yield 'a property under @nest that is not defined' => [
            '{"ex:p": [{"@value": 1}]}',
            DataLossCondition::UndefinedProperty,
            'name',
            '{"ex:p": 1, "@nest": {"name": "A"}}',
        ];
        yield 'a key that has the form of a keyword' => [
            '{"ex:p": [{"@value": 1}]}',
            DataLossCondition::ReservedTerm,
            '@ignoreMe',
            '{"ex:p": 1, "@ignoreMe": "A"}',
        ];
        yield 'a keyword that has no meaning in a node' => [
            '{"ex:p": [{"@value": 1}]}',
            DataLossCondition::UndefinedProperty,
            '@container',
            '{"ex:p": 1, "@container": "@set"}',
        ];
        yield 'an @id that has the form of a keyword' => [
            '{"@id": null, "ex:p": [{"@value": 1}]}',
            DataLossCondition::ReservedTerm,
            '@ignoreMe',
            '{"@id": "@ignoreMe", "ex:p": 1}',
        ];
        yield 'a @type that has the form of a keyword' => [
            '{"@type": [null], "ex:p": [{"@value": 1}]}',
            DataLossCondition::ReservedTerm,
            '@ignoreMe',
            '{"@type": "@ignoreMe", "ex:p": 1}',
        ];
        yield 'a @type in a list that is defined as null' => [
            '{"@type": ["ex:A", null], "ex:p": [{"@value": 1}]}',
            DataLossCondition::UndefinedProperty,
            'gone',
            '{"@context": {"@vocab": "ex:", "gone": null}, "@type": ["A", "gone"], "ex:p": 1}',
        ];
        yield 'a value of a term whose values are IRIs, with the form of a keyword' => [
            '{"ex:p": [{"@id": null}]}',
            DataLossCondition::ReservedTerm,
            '@ignoreMe',
            '{"@context": {"p": {"@id": "ex:p", "@type": "@id"}}, "p": "@ignoreMe"}',
        ];
        yield 'a value of a term whose values are vocabulary terms, with the form of a keyword' => [
            '{"ex:p": [{"@id": null}]}',
            DataLossCondition::ReservedTerm,
            '@ignoreMe',
            '{"@context": {"p": {"@id": "ex:p", "@type": "@vocab"}}, "p": "@ignoreMe"}',
        ];
        yield 'a value of a term whose values are vocabulary terms, defined as null' => [
            '{"ex:p": [{"@id": null}]}',
            DataLossCondition::UndefinedProperty,
            'gone',
            '{"@context": {"@vocab": "ex:", "gone": null, "p": {"@type": "@vocab"}}, "p": "gone"}',
        ];
        yield 'an identifier map key that has the form of a keyword' => [
            '{"ex:p": [{"@id": null, "ex:q": [{"@value": 1}]}]}',
            DataLossCondition::ReservedTerm,
            '@ignoreMe',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@id"}}, "p": {"@ignoreMe": {"ex:q": 1}}}',
        ];
        yield 'a type map key that has the form of a keyword' => [
            '{"ex:p": [{"@type": [null], "ex:q": [{"@value": 1}]}]}',
            DataLossCondition::ReservedTerm,
            '@ignoreMe',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@type"}}, "p": {"@ignoreMe": {"ex:q": 1}}}',
        ];
        yield 'a type map key that is defined as null' => [
            '{"ex:p": [{"@type": [null], "ex:q": [{"@value": 1}]}]}',
            DataLossCondition::UndefinedProperty,
            'gone',
            '{"@context": {"@vocab": "ex:", "gone": null, "p": {"@container": "@type"}}, "p": {"gone": {"q": 1}}}',
        ];
        yield 'an index that becomes a vocabulary term and has the form of a keyword' => [
            '{"ex:p": [{"@id": "ex:a", "ex:key": [{"@id": null}]}]}',
            DataLossCondition::ReservedTerm,
            '@ignoreMe',
            '{"@context": {"@vocab": "ex:", "key": {"@type": "@vocab"},'
                . ' "p": {"@container": "@index", "@index": "key"}}, "p": {"@ignoreMe": {"@id": "ex:a"}}}',
        ];
        yield 'an index that becomes a vocabulary term that is defined as null' => [
            '{"ex:p": [{"@id": "ex:a", "ex:key": [{"@id": null}]}]}',
            DataLossCondition::UndefinedProperty,
            'gone',
            '{"@context": {"@vocab": "ex:", "gone": null, "key": {"@type": "@vocab"},'
                . ' "p": {"@container": "@index", "@index": "key"}}, "p": {"gone": {"@id": "ex:a"}}}',
        ];
        yield 'a property-valued index whose property is defined as null' => [
            '{"ex:p": [{"ex:q": [{"@value": 1}]}]}',
            DataLossCondition::UndefinedProperty,
            'prop',
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@index", "@index": "prop"}, "prop": null},'
                . ' "p": {"a": {"q": 1}}}',
        ];
        yield 'a property-valued index over a value object, with the property defined as null' => [
            '{"ex:p": [{"@value": "v"}]}',
            DataLossCondition::UndefinedProperty,
            'prop',
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@index", "@index": "prop"}, "prop": null},'
                . ' "p": {"a": {"@value": "v"}}}',
        ];
        yield 'a value object whose value is null' => [
            '{"ex:q": [{"@value": 1}]}',
            DataLossCondition::NullValue,
            '{"@value":null}',
            '{"ex:p": {"@value": null}, "ex:q": 1}',
        ];
        yield 'a typed value object whose value is null' => [
            '{"ex:q": [{"@value": 1}]}',
            DataLossCondition::NullValue,
            '{"@type":"ex:t","@value":null}',
            '{"ex:p": {"@value": null, "@type": "ex:t"}, "ex:q": 1}',
        ];
        yield 'a value object whose value is an empty array' => [
            '{"ex:q": [{"@value": 1}]}',
            DataLossCondition::NullValue,
            '{"@type":["@json","ex:t"],"@value":[]}',
            '{"@context": {"type": "@type"}, "ex:p": {"@value": [], "@type": "@json", "type": "ex:t"}, "ex:q": 1}',
        ];
        yield 'a map with nothing but a language' => [
            '{"ex:q": [{"@value": 1}]}',
            DataLossCondition::FreeFloatingValue,
            '{"@language":"en"}',
            '{"ex:p": {"@language": "en"}, "ex:q": 1}',
        ];
        yield 'a scalar at the top level' => ['null', DataLossCondition::FreeFloatingNode, 'free', '"free"'];
        yield 'a number in a top-level array' => [
            '[{"ex:p": [{"@value": 1}]}]',
            DataLossCondition::FreeFloatingNode,
            '5',
            '[{"ex:p": 1}, 5]',
        ];
        yield 'a scalar in a graph' => [
            '{"@id": "ex:g", "@graph": []}',
            DataLossCondition::FreeFloatingNode,
            'true',
            '{"@id": "ex:g", "@graph": [true]}',
        ];
        yield 'a scalar in an included block' => [
            '{"ex:p": [{"@value": 1}], "@included": [{"ex:q": [{"@value": 2}]}]}',
            DataLossCondition::FreeFloatingNode,
            'free',
            '{"ex:p": 1, "@included": [{"ex:q": 2}, "free"]}',
        ];
        yield 'a node reference in an included block of a node further down' => [
            '{"ex:p": [{"@included": [], "ex:q": [{"@value": 1}]}]}',
            DataLossCondition::FreeFloatingNode,
            '{"@id":"ex:a"}',
            '{"ex:p": {"@included": [{"@id": "ex:a"}], "ex:q": 1}}',
        ];
        yield 'an empty map at the top level' => ['null', DataLossCondition::FreeFloatingNode, '{}', '{}'];
        yield 'a map with nothing but a context' => [
            'null',
            DataLossCondition::FreeFloatingNode,
            '{}',
            '{"@context": {"@vocab": "ex:"}}',
        ];
        yield 'a node reference at the top level' => [
            'null',
            DataLossCondition::FreeFloatingNode,
            '{"@id":"ex:a"}',
            '{"@id": "ex:a"}',
        ];
        yield 'a node reference in a graph' => [
            '{"@id": "ex:g", "@graph": [{"@id": "ex:b", "ex:p": [{"@value": 1}]}]}',
            DataLossCondition::FreeFloatingNode,
            '{"@id":"ex:a"}',
            '{"@id": "ex:g", "@graph": [{"@id": "ex:a"}, {"@id": "ex:b", "ex:p": 1}]}',
        ];
        yield 'a value object at the top level' => [
            'null',
            DataLossCondition::FreeFloatingValue,
            '{"@language":"en","@value":"free"}',
            '{"@value": "free", "@language": "en"}',
        ];
        yield 'a list at the top level' => [
            'null',
            DataLossCondition::FreeFloatingNode,
            '["free"]',
            '{"@list": ["free"]}',
        ];
        yield 'a list in a graph' => [
            '{"@id": "ex:g", "@graph": []}',
            DataLossCondition::FreeFloatingNode,
            '[]',
            '{"@id": "ex:g", "@graph": [{"@list": []}]}',
        ];
        yield 'a set at the top level whose values are free-floating' => [
            '[]',
            DataLossCondition::FreeFloatingNode,
            'free',
            '{"@set": ["free"]}',
        ];
    }

    public function testStrictModeKeepsAKeywordThatIsATypeOfItsOwn(): void
    {
        $this->assertExpandsTo(
            '{"ex:p": [{"@value": [1], "@type": "@json"}]}',
            '{"ex:p": {"@value": [1], "@type": "@json"}}',
        );
        $this->assertExpandsTo(
            '{"ex:p": [{"@value": [1], "@type": "@json"}]}',
            '{"@context": {"type": "@type"}, "ex:p": {"@value": [1], "type": "@json"}}',
        );
    }

    public function testStrictModeKeepsAMapKeyThatIsNotExpandedAsAnIri(): void
    {
        $this->assertExpandsTo(
            '{"ex:p": [{"@value": 1, "@index": "@ignoreMe"}]}',
            '{"@context": {"p": {"@id": "ex:p", "@container": "@index"}}, "p": {"@ignoreMe": 1}}',
        );
        $this->assertExpandsTo(
            '{"ex:label": [{"@value": "A", "@language": "@ignoreme"}]}',
            '{"@context": {"label": {"@id": "ex:label", "@container": "@language"}}, "label": {"@ignoreMe": "A"}}',
        );
        $this->assertExpandsTo(
            '{"ex:p": [{"ex:q": [{"@value": 1}]}]}',
            '{"@context": {"@vocab": "ex:", "p": {"@container": "@type"}}, "p": {"@none": {"q": 1}}}',
        );
    }

    public function testAnIncludedBlockThatIsDroppedWholeIsAnErrorInLenientMode(): void
    {
        $this->expectExceptionObject(new JsonLdError(ErrorCode::InvalidIncludedValue));

        self::expand('{"ex:p": 1, "@included": "free"}', strict: false);
    }

    public function testAFreeFloatingListAndItsAliasDoNotCollide(): void
    {
        $this->assertExpandsTo('null', '{"@context": {"list": "@list"}, "@list": [], "list": []}', strict: false);
    }

    public function testASetOfOneMapIsThatMap(): void
    {
        $this->assertExpandsTo(
            '{"ex:p": [{"@id": "ex:a"}]}',
            '{"ex:p": {"@set": {"@id": "ex:a"}}}',
        );
        $this->assertExpandsTo('[{"ex:q": [{"@value": 1}]}]', '{"@set": {"ex:q": 1}}');
    }

    public function testAValueOfAMapKeepsAContextThatDoesNotPropagate(): void
    {
        $context = '{"@vocab": "ex:", "p": {"@container": "@index"},'
            . ' "Outer": {"@context": {"q": "ex:scoped"}}}';

        $this->assertExpandsTo(
            '{"@type": ["ex:Outer"], "ex:p": [{"@index": "a", "ex:scoped": [{"@value": 1}]}]}',
            '{"@context": ' . $context . ', "@type": "Outer", "p": {"a": {"q": 1}}}',
        );
    }

    private function assertExpandsTo(
        string $expected,
        string $document,
        bool $strict = true,
        ProcessingMode $mode = ProcessingMode::JsonLd11,
    ): void {
        $this->assertSame(
            JsonCanonicalizer::canonicalize(json_decode($expected, flags: JSON_THROW_ON_ERROR)),
            JsonCanonicalizer::canonicalize(self::expand($document, $strict, $mode)),
        );
    }

    private static function expand(
        string $document,
        bool $strict = true,
        ProcessingMode $mode = ProcessingMode::JsonLd11,
        ?string $base = self::BASE,
    ): mixed {
        $options = new Options(processingMode: $mode, strict: $strict);
        $expander = new Expander($options, new ContextProcessor($options));

        return $expander->expand(
            ActiveContext::initial($base),
            null,
            json_decode($document, flags: JSON_THROW_ON_ERROR),
            $base,
        );
    }
}
