<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Benchmark;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\Processor;
use stdClass;

use function array_fill;
use function chr;
use function intdiv;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Measures context processing that creates no term definition
 *
 * In the first three shapes, the property `items` has a scoped context of m
 * items that define nothing, and the document has n nodes that each have an
 * empty context of their own and the property, so the scoped context is
 * processed n times and no result is used twice. The items are empty maps,
 * `null`s, or terms with the form of a keyword, which lenient mode ignores. In
 * the fourth shape, the document's context defines m terms, and each of the n
 * nodes has a context of `null`, which asks whether any of the m terms is
 * protected.
 *
 * Each shape comes in two sizes, one double the other in both m and n. A
 * document that a limit refuses is still measured: the time is the time taken
 * to reach the refusal.
 */
final class ContextOperationBench
{
    private string $document = '';
    private Processor $processor;

    public function __construct()
    {
        $this->processor = new Processor();
    }

    /**
     * @param array{items: int, nodes: int} $params
     */
    #[BeforeMethods('setUpEmptyMaps')]
    #[ParamProviders('sizes')]
    #[Iterations(3)]
    public function benchEmptyMapsForEachNode(array $params): void
    {
        $this->expand();
    }

    /**
     * @param array{items: int, nodes: int} $params
     */
    #[BeforeMethods('setUpNulls')]
    #[ParamProviders('sizes')]
    #[Iterations(3)]
    public function benchNullsForEachNode(array $params): void
    {
        $this->expand();
    }

    /**
     * @param array{items: int, nodes: int} $params
     */
    #[BeforeMethods('setUpIgnoredTerms')]
    #[ParamProviders('sizes')]
    #[Iterations(3)]
    public function benchIgnoredTermsForEachNode(array $params): void
    {
        $this->expand();
    }

    /**
     * @param array{terms: int, nodes: int} $params
     */
    #[BeforeMethods('setUpANullContextOverALargeContext')]
    #[ParamProviders('largeContextSizes')]
    #[Iterations(3)]
    public function benchANullContextOverALargeContext(array $params): void
    {
        $this->processor->expand($this->document);
    }

    /**
     * @return iterable<string, array{items: int, nodes: int}>
     */
    public function sizes(): iterable
    {
        yield '1,000 by 1,000' => ['items' => 1_000, 'nodes' => 1_000];
        yield '2,000 by 2,000' => ['items' => 2_000, 'nodes' => 2_000];
    }

    /**
     * @return iterable<string, array{terms: int, nodes: int}>
     */
    public function largeContextSizes(): iterable
    {
        yield '5,000 terms, 2,500 nodes' => ['terms' => 5_000, 'nodes' => 2_500];
        yield '10,000 terms, 5,000 nodes' => ['terms' => 10_000, 'nodes' => 5_000];
    }

    /**
     * @param array{items: int, nodes: int} $params
     */
    public function setUpEmptyMaps(array $params): void
    {
        $this->processor = new Processor();
        $this->document = self::document(array_fill(0, $params['items'], new stdClass()), $params['nodes']);
    }

    /**
     * @param array{items: int, nodes: int} $params
     */
    public function setUpNulls(array $params): void
    {
        $this->processor = new Processor();
        $this->document = self::document(array_fill(0, $params['items'], null), $params['nodes']);
    }

    /**
     * @param array{items: int, nodes: int} $params
     */
    public function setUpIgnoredTerms(array $params): void
    {
        $scoped = [];

        for ($i = 0; $i < $params['items']; $i++) {
            $scoped[sprintf('@zz%s', self::letters($i))] = 'https://example.com/ns#ignored';
        }

        $this->processor = new Processor(new Options(strict: false));
        $this->document = self::document($scoped, $params['nodes']);
    }

    /**
     * @param array{terms: int, nodes: int} $params
     */
    public function setUpANullContextOverALargeContext(array $params): void
    {
        $context = [];

        for ($i = 0; $i < $params['terms']; $i++) {
            $context[sprintf('term%d', $i)] = sprintf('https://example.com/ns#term%d', $i);
        }

        $nodes = [];

        for ($i = 0; $i < $params['nodes']; $i++) {
            $nodes[] = ['@context' => null, 'https://example.com/ns#p' => $i];
        }

        $this->processor = new Processor();
        $this->document = json_encode(['@context' => $context, '@graph' => $nodes], JSON_THROW_ON_ERROR);
    }

    /**
     * Returns a document whose term `items` has the given scoped context, and
     * whose nodes each have an empty context of their own and the property
     *
     * @param array<mixed> $scoped
     */
    private static function document(array $scoped, int $nodes): string
    {
        $graph = [];

        for ($i = 0; $i < $nodes; $i++) {
            $graph[] = ['@context' => new stdClass(), 'items' => sprintf('value %d', $i)];
        }

        return json_encode(
            [
                '@context' => ['items' => ['@id' => 'https://example.com/ns#items', '@context' => $scoped]],
                '@graph' => $graph,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Returns a name made of lowercase letters alone, a different one for each
     * number, so that `@zz` and the name together have the form of a keyword
     * without being one
     */
    private static function letters(int $number): string
    {
        $name = '';

        do {
            $name .= chr(97 + $number % 26);
            $number = intdiv($number, 26);
        } while ($number > 0);

        return $name;
    }

    private function expand(): void
    {
        try {
            $this->processor->expand($this->document);
        } catch (LimitExceeded) {
            // The time taken to reach the refusal is what was measured.
        }
    }
}
