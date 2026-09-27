<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Benchmark;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use SocialWeb\JsonLd\Processor;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Measures many one-term contexts over a large active context
 *
 * The document's context defines many terms, and each of its nodes has an
 * inline context that defines one more. Each inline context copies the term
 * definitions of the active context once. The smaller size is the largest that
 * a `maxValues` of 10,000 allows, and the larger size is the largest that the
 * default `maxValues` allows.
 */
final class ContextCopyBench
{
    private string $document = '';

    /**
     * @param array{terms: int, nodes: int} $params
     */
    #[BeforeMethods('setUp')]
    #[ParamProviders('sizes')]
    #[Iterations(3)]
    public function benchAOneTermContextForEachNode(array $params): void
    {
        (new Processor())->expand($this->document);
    }

    /**
     * @return iterable<string, array{terms: int, nodes: int}>
     */
    public function sizes(): iterable
    {
        yield '5,000 terms, 1,249 nodes' => ['terms' => 5_000, 'nodes' => 1_249];
        yield '50,000 terms, 12,499 nodes' => ['terms' => 50_000, 'nodes' => 12_499];
    }

    /**
     * @param array{terms: int, nodes: int} $params
     */
    public function setUp(array $params): void
    {
        $context = [];

        for ($i = 0; $i < $params['terms']; $i++) {
            $context[sprintf('term%d', $i)] = sprintf('https://example.com/ns#term%d', $i);
        }

        $nodes = [];

        for ($i = 0; $i < $params['nodes']; $i++) {
            $nodes[] = ['@context' => ['own' => sprintf('https://example.com/ns#own%d', $i)], 'own' => $i];
        }

        $this->document = json_encode(['@context' => $context, '@graph' => $nodes], JSON_THROW_ON_ERROR);
    }
}
