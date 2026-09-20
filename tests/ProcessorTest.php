<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\JsonLd\Exception\MalformedJson;
use SocialWeb\JsonLd\Exception\RestrictedFeature;
use SocialWeb\JsonLd\Limits;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\ProcessingMode;
use SocialWeb\JsonLd\Processor;
use SocialWeb\JsonLd\Restrictions;

use function json_decode;

use const JSON_THROW_ON_ERROR;

class ProcessorTest extends TestCase
{
    private const string DOCUMENT = <<<'JSON'
        {
            "@context": {"@vocab": "https://example.com/ns#", "knows": {"@type": "@id"}},
            "@id": "https://example.com/a",
            "@type": "Person",
            "name": "A",
            "knows": ["https://example.com/b", "https://example.com/c"]
        }
        JSON;

    private const string EXPANDED = '[{"@id":"https://example.com/a","@type":["https://example.com/ns#Person"],'
        . '"https://example.com/ns#knows":[{"@id":"https://example.com/b"},{"@id":"https://example.com/c"}],'
        . '"https://example.com/ns#name":[{"@value":"A"}]}]';

    public function testExpandsAString(): void
    {
        $this->assertSame(self::EXPANDED, (new Processor())->expand(self::DOCUMENT)->toJson());
    }

    public function testExpandsADecodedDocument(): void
    {
        $processor = new Processor();

        $asObjects = json_decode(self::DOCUMENT, false, 512, JSON_THROW_ON_ERROR);
        $asArrays = json_decode(self::DOCUMENT, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsObject($asObjects);
        $this->assertIsArray($asArrays);

        $this->assertSame(self::EXPANDED, $processor->expand($asObjects)->toJson());
        $this->assertSame(self::EXPANDED, $processor->expand($asArrays)->toJson());
    }

    public function testDoesNotChangeTheDocumentItIsGiven(): void
    {
        $document = json_decode(self::DOCUMENT, false, 512, JSON_THROW_ON_ERROR);
        $this->assertIsObject($document);
        $before = json_decode(self::DOCUMENT, false, 512, JSON_THROW_ON_ERROR);

        (new Processor())->expand($document);

        $this->assertEquals($before, $document);
    }

    #[DataProvider('topLevels')]
    public function testTheResultIsAlwaysAListOfNodes(string $expected, string $document): void
    {
        $this->assertSame($expected, (new Processor(new Options(strict: false)))->expand($document)->toJson());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function topLevels(): iterable
    {
        yield 'one node' => ['[{"ex:p":[{"@value":1}]}]', '{"ex:p": 1}'];
        yield 'an array of nodes' => [
            '[{"ex:p":[{"@value":1}]},{"ex:p":[{"@value":2}]}]',
            '[{"ex:p": 1}, {"ex:p": 2}]',
        ];
        yield 'a map with nothing but @graph stands for its contents' => [
            '[{"ex:p":[{"@value":1}]},{"ex:p":[{"@value":2}]}]',
            '{"@context": {"p": "ex:p"}, "@graph": [{"p": 1}, {"p": 2}]}',
        ];
        yield 'a map with @graph and an identifier is a named graph' => [
            '[{"@graph":[{"ex:p":[{"@value":1}]}],"@id":"ex:g"}]',
            '{"@id": "ex:g", "@graph": [{"ex:p": 1}]}',
        ];
        yield 'a map with @graph and an index is kept too' => [
            '[{"@graph":[{"ex:p":[{"@value":1}]}],"@index":"a"}]',
            '{"@index": "a", "@graph": [{"ex:p": 1}]}',
        ];
        yield 'nothing' => ['[]', '{"@context": {"p": "ex:p"}}'];
        yield 'an empty array' => ['[]', '[]'];
        yield 'an empty graph' => ['[]', '{"@graph": []}'];
        yield 'a scalar' => ['[]', '"free"'];
        yield 'null' => ['[]', 'null'];
    }

    public function testResolvesRelativeReferencesAgainstTheBase(): void
    {
        $document = '{"@id": "a", "ex:p": {"@id": "../b"}}';

        $this->assertSame(
            '[{"@id":"https://example.com/dir/a","ex:p":[{"@id":"https://example.com/b"}]}]',
            (new Processor(new Options(base: 'https://example.com/dir/doc')))->expand($document)->toJson(),
        );
        $this->assertSame('[{"@id":"a","ex:p":[{"@id":"../b"}]}]', (new Processor())->expand($document)->toJson());
    }

    public function testUsesTheBundledContextsByDefault(): void
    {
        $document = '{"@context": "https://www.w3.org/ns/activitystreams", "type": "Note", "content": "Hello"}';

        $this->assertSame(
            '[{"@type":["https://www.w3.org/ns/activitystreams#Note"],'
                . '"https://www.w3.org/ns/activitystreams#content":[{"@value":"Hello"}]}]',
            (new Processor())->expand($document)->toJson(),
        );
    }

    public function testResolvesAContextUrlAgainstTheBase(): void
    {
        $loader = (new BundledDocumentLoader())
            ->with('https://example.com/contexts/v1', '{"@context": {"name": "https://example.com/ns#name"}}');
        $processor = new Processor(new Options(base: 'https://example.com/dir/doc', documentLoader: $loader));

        $this->assertSame(
            '[{"https://example.com/ns#name":[{"@value":"A"}]}]',
            $processor->expand('{"@context": "../contexts/v1", "name": "A"}')->toJson(),
        );
    }

    public function testAProcessorMayExpandManyDocuments(): void
    {
        $processor = new Processor();
        $first = '{"@context": "https://www.w3.org/ns/activitystreams", "type": "Note"}';
        $second = '{"@context": {"type": "https://example.com/ns#type"}, "type": "Note"}';

        $note = '[{"@type":["https://www.w3.org/ns/activitystreams#Note"]}]';

        $this->assertSame($note, $processor->expand($first)->toJson());
        $this->assertSame(
            '[{"https://example.com/ns#type":[{"@value":"Note"}]}]',
            $processor->expand($second)->toJson(),
        );
        $this->assertSame($note, $processor->expand($first)->toJson());
    }

    /**
     * @param string | array<mixed> | object $expandContext
     */
    #[DataProvider('expandContexts')]
    public function testAppliesTheExpandContextBeforeTheDocumentsOwn(string | array | object $expandContext): void
    {
        $loader = (new BundledDocumentLoader())->with(
            'https://example.com/contexts/v1',
            '{"@context": {"name": "https://example.com/ns#name", "label": "https://example.com/ns#label"}}',
        );
        $processor = new Processor(new Options(expandContext: $expandContext, documentLoader: $loader));

        $this->assertSame(
            '[{"https://example.com/ns#label":[{"@value":"B"}],"https://example.com/other#name":[{"@value":"A"}]}]',
            $processor->expand('{"@context": {"name": "https://example.com/other#name"}, "name": "A", "label": "B"}')
                ->toJson(),
        );
    }

    /**
     * @return iterable<string, array{string | array<mixed> | object}>
     */
    public static function expandContexts(): iterable
    {
        $context = ['name' => 'https://example.com/ns#name', 'label' => 'https://example.com/ns#label'];

        yield 'a context URL' => ['https://example.com/contexts/v1'];
        yield 'a context, as an array' => [$context];
        yield 'a context, as an object' => [(object) $context];
        yield 'a document with a context, as an array' => [['@context' => $context]];
        yield 'a document with a context, as an object' => [(object) ['@context' => (object) $context]];
        yield 'a document whose context is a URL' => [['@context' => 'https://example.com/contexts/v1']];
        yield 'a list of contexts' => [[['name' => 'https://example.com/ns#name'], 'https://example.com/contexts/v1']];
    }

    public function testAnExpandContextOfNullInsideADocumentResetsNothingThatWasNotThere(): void
    {
        $processor = new Processor(new Options(expandContext: ['@context' => null]));

        $this->assertSame('[{"ex:p":[{"@value":1}]}]', $processor->expand('{"ex:p": 1}')->toJson());
    }

    public function testIsStrictByDefault(): void
    {
        try {
            (new Processor())->expand('{"ex:p": 1, "undefined": 2}');
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame(DataLossCondition::UndefinedProperty, $dataLoss->condition);
            $this->assertSame('undefined', $dataLoss->detail);
        }

        $this->assertSame(
            '[{"ex:p":[{"@value":1}]}]',
            (new Processor(new Options(strict: false)))->expand('{"ex:p": 1, "undefined": 2}')->toJson(),
        );
    }

    public function testStrictModeAlsoCoversTheExpandContext(): void
    {
        $processor = new Processor(new Options(expandContext: ['@ignoreMe' => 'https://example.com/ns#ignored']));

        $this->expectExceptionObject(new DataLoss(DataLossCondition::ReservedTerm, '@ignoreMe'));

        $processor->expand('{"ex:p": 1}');
    }

    public function testHonorsTheProcessingMode(): void
    {
        $processor = new Processor(new Options(processingMode: ProcessingMode::JsonLd10));

        $this->expectExceptionObject(new JsonLdError(ErrorCode::ProcessingModeConflict));

        $processor->expand('{"@context": {"@version": 1.1}, "ex:p": 1}');
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(MalformedJson::class);

        (new Processor())->expand('{"ex:p": ');
    }

    public function testRejectsADocumentThatHoldsSomethingOtherThanJsonValues(): void
    {
        $this->expectException(InvalidArgument::class);

        (new Processor())->expand(['ex:p' => new ArrayObject()]);
    }

    public function testEnforcesTheLimitsOnTheDocument(): void
    {
        $processor = new Processor(new Options(limits: new Limits(maxDepth: 2, maxValues: 4)));

        $this->assertSame('[{"ex:p":[{"@value":1}]}]', $processor->expand('{"ex:p": [1]}')->toJson());

        try {
            $processor->expand('{"ex:p": [[1]]}');
            $this->fail('Expected a LimitExceeded');
        } catch (LimitExceeded $exception) {
            $this->assertSame('maxDepth', $exception->limit);
        }

        try {
            $processor->expand('{"ex:p": [1, 2, 3]}');
            $this->fail('Expected a LimitExceeded');
        } catch (LimitExceeded $exception) {
            $this->assertSame('maxValues', $exception->limit);
        }
    }

    public function testEnforcesTheLimitsOnTheExpandContext(): void
    {
        $processor = new Processor(new Options(
            expandContext: ['a' => 'ex:a', 'b' => 'ex:b', 'c' => 'ex:c'],
            limits: new Limits(maxValues: 3),
        ));

        $this->expectExceptionObject(new LimitExceeded('maxValues', 3));

        $processor->expand('{"ex:p": 1}');
    }

    public function testTheLimitsCountTheDocumentAndTheExpandContextApart(): void
    {
        $processor = new Processor(new Options(
            expandContext: ['a' => 'ex:a', 'b' => 'ex:b'],
            limits: new Limits(maxValues: 3),
        ));

        $this->assertSame(
            '[{"ex:a":[{"@value":1}],"ex:b":[{"@value":2}]}]',
            $processor->expand('{"a": 1, "b": 2}')->toJson(),
        );
    }

    public function testAppliesTheRestrictionsToTheExpandedDocument(): void
    {
        $document = '{"@context": {"graph": "@graph", "id": "@id"}, "id": "ex:g", "graph": [{"ex:p": 1}]}';

        $this->assertSame(
            '[{"@graph":[{"ex:p":[{"@value":1}]}],"@id":"ex:g"}]',
            (new Processor())->expand($document)->toJson(),
        );

        try {
            (new Processor(new Options(restrictions: new Restrictions(forbidNamedGraphs: true))))->expand($document);
            $this->fail('Expected a RestrictedFeature');
        } catch (RestrictedFeature $exception) {
            $this->assertSame('forbidNamedGraphs', $exception->restriction);
            $this->assertSame('ex:g', $exception->detail);
        }
    }

    public function testATopLevelGraphIsNotANamedGraphButItsNodesAreCounted(): void
    {
        $document = '{"@graph": [{"ex:p": 1}, {"ex:p": 2}]}';

        $this->assertCount(
            2,
            (new Processor(new Options(restrictions: new Restrictions(forbidNamedGraphs: true))))
                ->expand($document)
                ->jsonSerialize(),
        );

        $this->expectExceptionObject(new RestrictedFeature('requireSingleTopLevelNode', '2'));

        (new Processor(new Options(restrictions: Restrictions::all())))->expand($document);
    }
}
