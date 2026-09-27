<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Benchmark;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use RuntimeException;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\Processor;
use SocialWeb\Test\JsonLd\W3c\FixtureDocumentLoader;
use SocialWeb\Test\JsonLd\W3c\W3cManifest;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Measures ordinary documents, which a change that bounds the work of other
 * documents must not slow down
 */
final class BaselineBench
{
    private const string ACTIVITY_STREAMS = 'https://www.w3.org/ns/activitystreams';

    /**
     * Inputs of the W3C `expand` suite, by identifier: an index map, a larger
     * document with nested nodes, scoped contexts under `@nest`, scoped
     * contexts with protected terms, and type-scoped with property-scoped
     * contexts
     */
    private const array W3C_ENTRIES = ['#t0036', '#tin06', '#tc038', '#tpr25', '#tc024'];

    private string $document = '';
    private Processor $processor;

    public function __construct()
    {
        $this->processor = new Processor();
    }

    /**
     * A page of a collection whose items each name the same context by URL
     *
     * @param array{items: int} $params
     */
    #[BeforeMethods('setUpPage')]
    #[ParamProviders('pages')]
    public function benchAPageOfObjectsThatRepeatAContextUrl(array $params): void
    {
        $this->processor->expand($this->document);
    }

    /**
     * Nodes inside a node whose type has a scoped context
     *
     * A type-scoped context does not propagate, so the algorithm asks of each
     * of the inner nodes whether it begins a new node object.
     *
     * @param array{items: int} $params
     */
    #[BeforeMethods('setUpNodesUnderATypeScopedContext')]
    #[ParamProviders('manyItems')]
    public function benchNodesUnderATypeScopedContext(array $params): void
    {
        $this->processor->expand($this->document);
    }

    /**
     * Maps under an alias of `@nest`
     *
     * The algorithm asks of each of the maps whether it has an `@value` entry.
     *
     * @param array{items: int} $params
     */
    #[BeforeMethods('setUpMapsUnderNest')]
    #[ParamProviders('manyItems')]
    public function benchMapsUnderNest(array $params): void
    {
        $this->processor->expand($this->document);
    }

    /**
     * @param array{id: string} $params
     */
    #[BeforeMethods('setUpW3cEntry')]
    #[ParamProviders('w3cEntries')]
    #[Revs(20)]
    public function benchAW3cInput(array $params): void
    {
        $this->processor->expand($this->document);
    }

    /**
     * @return iterable<string, array{items: int}>
     */
    public function pages(): iterable
    {
        yield '500 items' => ['items' => 500];
        yield '1,000 items' => ['items' => 1_000];
    }

    /**
     * @return iterable<string, array{items: int}>
     */
    public function manyItems(): iterable
    {
        yield '5,000 items' => ['items' => 5_000];
        yield '10,000 items' => ['items' => 10_000];
    }

    /**
     * @return iterable<string, array{id: string}>
     */
    public function w3cEntries(): iterable
    {
        foreach (self::W3C_ENTRIES as $id) {
            yield $id => ['id' => $id];
        }
    }

    /**
     * @param array{items: int} $params
     */
    public function setUpPage(array $params): void
    {
        $items = [];

        for ($i = 0; $i < $params['items']; $i++) {
            $items[] = [
                '@context' => self::ACTIVITY_STREAMS,
                'id' => sprintf('https://example.com/notes/%d', $i),
                'type' => 'Note',
                'attributedTo' => 'https://example.com/people/a',
                'content' => sprintf('Note %d', $i),
                'published' => '2026-09-26T12:00:00Z',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ];
        }

        $this->processor = new Processor();
        $this->document = json_encode(
            [
                '@context' => self::ACTIVITY_STREAMS,
                'id' => 'https://example.com/outbox?page=1',
                'type' => 'OrderedCollectionPage',
                'partOf' => 'https://example.com/outbox',
                'orderedItems' => $items,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array{items: int} $params
     */
    public function setUpNodesUnderATypeScopedContext(array $params): void
    {
        $this->processor = new Processor();
        $this->document = json_encode(
            [
                '@context' => [
                    '@vocab' => 'https://example.com/ns#',
                    'Group' => ['@id' => 'https://example.com/ns#Group', '@context' => ['label' => 'title']],
                ],
                '@type' => 'Group',
                'label' => 'A group',
                'member' => self::items($params['items']),
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array{items: int} $params
     */
    public function setUpMapsUnderNest(array $params): void
    {
        $this->processor = new Processor();
        $this->document = json_encode(
            [
                '@context' => ['@vocab' => 'https://example.com/ns#', 'details' => '@nest'],
                '@id' => 'https://example.com/nodes/main',
                'details' => self::items($params['items']),
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array{id: string} $params
     */
    public function setUpW3cEntry(array $params): void
    {
        foreach (W3cManifest::entries(W3cManifest::EXPAND, W3cManifest::POSITIVE) as $id => [$entry]) {
            if ($id !== $params['id']) {
                continue;
            }

            $this->processor = new Processor(new Options(
                base: $entry->inputUrl,
                strict: false,
                documentLoader: new FixtureDocumentLoader(),
            ));
            $this->document = W3cManifest::read($entry->input);

            return;
        }

        throw new RuntimeException(sprintf('The W3C expand suite has no positive entry %s', $params['id']));
    }

    /**
     * Returns maps of five entries each
     *
     * @return list<array<string, string>>
     */
    private static function items(int $count): array
    {
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'name' => sprintf('Item %d', $i),
                'summary' => 'A summary',
                'created' => '2026-09-26',
                'status' => 'open',
                'owner' => 'A',
            ];
        }

        return $items;
    }
}
