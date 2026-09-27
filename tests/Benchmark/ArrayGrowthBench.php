<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Benchmark;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\ParamProviders;
use SocialWeb\JsonLd\Processor;

use function array_fill;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Measures lists that grow by one item or a few items at a time
 *
 * Each shape comes in two sizes, one double the other. If the time doubles with
 * the size, the growth is linear. If the time is four times as long, the growth
 * is quadratic.
 */
final class ArrayGrowthBench
{
    private string $document = '';

    /**
     * @param array{size: int} $params
     */
    #[BeforeMethods('setUpOneItemArrays')]
    #[ParamProviders('largeSizes')]
    public function benchOneItemArraysUnderOneProperty(array $params): void
    {
        (new Processor())->expand($this->document);
    }

    /**
     * @param array{size: int} $params
     */
    #[BeforeMethods('setUpTermsForOneProperty')]
    #[ParamProviders('sizes')]
    public function benchTermsForOneProperty(array $params): void
    {
        (new Processor())->expand($this->document);
    }

    /**
     * The same number of terms, each for a property of its own, as a control
     *
     * @param array{size: int} $params
     */
    #[BeforeMethods('setUpTermsForDifferentProperties')]
    #[ParamProviders('sizes')]
    public function benchTermsForDifferentProperties(array $params): void
    {
        (new Processor())->expand($this->document);
    }

    /**
     * @param array{size: int} $params
     */
    #[BeforeMethods('setUpAliasesOfType')]
    #[ParamProviders('sizes')]
    public function benchAliasesOfType(array $params): void
    {
        (new Processor())->expand($this->document);
    }

    /**
     * @param array{size: int} $params
     */
    #[BeforeMethods('setUpAliasesOfIncluded')]
    #[ParamProviders('sizes')]
    public function benchAliasesOfIncluded(array $params): void
    {
        (new Processor())->expand($this->document);
    }

    /**
     * @return iterable<string, array{size: int}>
     */
    public function sizes(): iterable
    {
        yield '10,000' => ['size' => 10_000];
        yield '20,000' => ['size' => 20_000];
    }

    /**
     * @return iterable<string, array{size: int}>
     */
    public function largeSizes(): iterable
    {
        yield '20,000' => ['size' => 20_000];
        yield '40,000' => ['size' => 40_000];
    }

    /**
     * @param array{size: int} $params
     */
    public function setUpOneItemArrays(array $params): void
    {
        $this->document = json_encode(
            ['https://example.com/ns#items' => array_fill(0, $params['size'], ['item'])],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array{size: int} $params
     */
    public function setUpTermsForOneProperty(array $params): void
    {
        $context = [];
        $node = [];

        for ($i = 0; $i < $params['size']; $i++) {
            $context[sprintf('term%d', $i)] = 'https://example.com/ns#items';
            $node[sprintf('term%d', $i)] = $i;
        }

        $this->document = json_encode(['@context' => $context] + $node, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{size: int} $params
     */
    public function setUpTermsForDifferentProperties(array $params): void
    {
        $context = [];
        $node = [];

        for ($i = 0; $i < $params['size']; $i++) {
            $context[sprintf('term%d', $i)] = sprintf('https://example.com/ns#term%d', $i);
            $node[sprintf('term%d', $i)] = $i;
        }

        $this->document = json_encode(['@context' => $context] + $node, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{size: int} $params
     */
    public function setUpAliasesOfType(array $params): void
    {
        $context = [];
        $node = [];

        for ($i = 0; $i < $params['size']; $i++) {
            $context[sprintf('type%d', $i)] = '@type';
            $node[sprintf('type%d', $i)] = sprintf('https://example.com/ns#Type%d', $i);
        }

        $this->document = json_encode(['@context' => $context] + $node, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{size: int} $params
     */
    public function setUpAliasesOfIncluded(array $params): void
    {
        $context = [];
        $node = ['@id' => 'https://example.com/nodes/main'];

        for ($i = 0; $i < $params['size']; $i++) {
            $context[sprintf('included%d', $i)] = '@included';
            $node[sprintf('included%d', $i)] = ['https://example.com/ns#label' => sprintf('Node %d', $i)];
        }

        $this->document = json_encode(['@context' => $context] + $node, JSON_THROW_ON_ERROR);
    }
}
