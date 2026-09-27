<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Benchmark;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\JsonLd\Processor;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Measures a scoped context of many terms that applies to many values
 *
 * The property `items` has a scoped context of m terms, and the document gives
 * the property n values, so the scoped context applies n times. In the first
 * shape, the values are strings, and the scoped context applies to the same
 * active context every time. In the second shape, each value is a node with an
 * inline context of its own, and inside it is the property with the scoped
 * context, so the scoped context applies to a different active context every
 * time.
 *
 * A document that a limit refuses is still measured: the time is the time
 * taken to reach the refusal.
 */
final class ScopedContextBench
{
    private string $document = '';

    /**
     * @param array{terms: int, values: int} $params
     */
    #[BeforeMethods('setUpOneActiveContext')]
    #[ParamProviders('sizes')]
    public function benchOneActiveContext(array $params): void
    {
        $this->expand();
    }

    /**
     * @param array{terms: int, values: int} $params
     */
    #[BeforeMethods('setUpOneActiveContext')]
    #[ParamProviders('largestSize')]
    #[Iterations(3)]
    public function benchOneActiveContextAtTheLargestSize(array $params): void
    {
        $this->expand();
    }

    /**
     * @param array{terms: int, values: int} $params
     */
    #[BeforeMethods('setUpAnActiveContextForEachValue')]
    #[ParamProviders('sizes')]
    public function benchAnActiveContextForEachValue(array $params): void
    {
        $this->expand();
    }

    /**
     * @param array{terms: int, values: int} $params
     */
    #[BeforeMethods('setUpAnActiveContextForEachValue')]
    #[ParamProviders('largestSize')]
    #[Iterations(3)]
    public function benchAnActiveContextForEachValueAtTheLargestSize(array $params): void
    {
        $this->expand();
    }

    /**
     * @return iterable<string, array{terms: int, values: int}>
     */
    public function sizes(): iterable
    {
        yield '500 by 500' => ['terms' => 500, 'values' => 500];
        yield '1,000 by 1,000' => ['terms' => 1_000, 'values' => 1_000];
    }

    /**
     * @return iterable<string, array{terms: int, values: int}>
     */
    public function largestSize(): iterable
    {
        yield '2,000 by 2,000' => ['terms' => 2_000, 'values' => 2_000];
    }

    /**
     * @param array{terms: int, values: int} $params
     */
    public function setUpOneActiveContext(array $params): void
    {
        $values = [];

        for ($i = 0; $i < $params['values']; $i++) {
            $values[] = sprintf('value %d', $i);
        }

        $this->document = json_encode(
            ['@context' => self::context($params['terms']), 'items' => $values],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array{terms: int, values: int} $params
     */
    public function setUpAnActiveContextForEachValue(array $params): void
    {
        $nodes = [];

        for ($i = 0; $i < $params['values']; $i++) {
            $nodes[] = [
                '@context' => ['own' => sprintf('https://example.com/ns#own%d', $i)],
                'items' => sprintf('value %d', $i),
            ];
        }

        $this->document = json_encode(
            ['@context' => self::context($params['terms']), '@graph' => $nodes],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Returns a context that defines `items` with a scoped context of the
     * given number of terms
     *
     * @return array<string, mixed>
     */
    private static function context(int $terms): array
    {
        $scoped = [];

        for ($i = 0; $i < $terms; $i++) {
            $scoped[sprintf('term%d', $i)] = sprintf('https://example.com/ns#term%d', $i);
        }

        return ['items' => ['@id' => 'https://example.com/ns#items', '@context' => $scoped]];
    }

    private function expand(): void
    {
        try {
            (new Processor())->expand($this->document);
        } catch (LimitExceeded) {
            // The time taken to reach the refusal is what was measured.
        }
    }
}
