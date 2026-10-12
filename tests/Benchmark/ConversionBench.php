<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Benchmark;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\Processor;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Measures conversion to RDF on documents whose size doubles, so that the
 * ratio between two sizes shows whether the work grows in proportion to the
 * document
 */
final class ConversionBench
{
    private const string VOCABULARY = 'https://example.com/ns#';

    private string $document = '';
    private Processor $processor;

    public function __construct()
    {
        $this->processor = new Processor(new Options(strict: false));
    }

    /**
     * Nodes that each have a type, three values, and a reference to the next
     * node
     *
     * @param array{items: int} $params
     */
    #[BeforeMethods('setUpNodes')]
    #[ParamProviders('sizes')]
    #[Revs(5)]
    public function benchNodesWithValuesAndReferences(array $params): void
    {
        $this->processor->toRdf($this->document);
    }

    /**
     * One list of many items, which conversion turns into a chain of blank
     * nodes
     *
     * @param array{items: int} $params
     */
    #[BeforeMethods('setUpList')]
    #[ParamProviders('sizes')]
    #[Revs(5)]
    public function benchALongList(array $params): void
    {
        $this->processor->toRdf($this->document);
    }

    /**
     * @param array{items: int} $params
     */
    public function setUpNodes(array $params): void
    {
        $nodes = [];

        for ($i = 0; $i < $params['items']; $i++) {
            $nodes[] = [
                '@id' => 'https://example.com/node/' . $i,
                '@type' => 'Thing',
                'name' => 'Node ' . $i,
                'count' => $i,
                'flag' => $i % 2 === 0,
                'next' => ['@id' => 'https://example.com/node/' . ($i + 1)],
            ];
        }

        $this->document = json_encode(
            ['@context' => ['@vocab' => self::VOCABULARY, 'next' => ['@type' => '@id']], '@graph' => $nodes],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array{items: int} $params
     */
    public function setUpList(array $params): void
    {
        $items = [];

        for ($i = 0; $i < $params['items']; $i++) {
            $items[] = 'Item ' . $i;
        }

        $this->document = json_encode(
            [
                '@context' => ['@vocab' => self::VOCABULARY, 'items' => ['@container' => '@list']],
                '@id' => 'https://example.com/list',
                'items' => $items,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return iterable<string, array{items: int}>
     */
    public function sizes(): iterable
    {
        yield '1000' => ['items' => 1_000];
        yield '2000' => ['items' => 2_000];
        yield '4000' => ['items' => 4_000];
    }
}
