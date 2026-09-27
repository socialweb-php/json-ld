<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\ProcessedContextCache;
use SocialWeb\JsonLd\Context\TermDefinition;
use SocialWeb\Test\JsonLd\TestCase;
use WeakReference;

use function array_fill;
use function gc_disable;
use function gc_enable;

class ProcessedContextCacheTest extends TestCase
{
    private const string URL = 'https://example.com/context';

    public function testStartsEmpty(): void
    {
        $cache = new ProcessedContextCache();

        $this->assertNull($cache->scoped(new ActiveContext(), self::definition(), false, true));
        $this->assertNull($cache->remote(new ActiveContext(), self::URL, false));
        $this->assertSame(0, $cache->termDefinitions());
    }

    public function testKeepsTheResultOfAScopedContext(): void
    {
        $cache = new ProcessedContextCache();
        $activeContext = new ActiveContext();
        $definition = self::definition();
        $result = self::processed(2);

        $cache->storeScoped($activeContext, $definition, false, true, $result);

        $this->assertSame($result, $cache->scoped($activeContext, $definition, false, true));
        $this->assertSame(2, $cache->termDefinitions());
    }

    public function testKeepsTheResultOfAContextNamedByUrl(): void
    {
        $cache = new ProcessedContextCache();
        $activeContext = new ActiveContext();
        $result = self::processed(3);

        $cache->storeRemote($activeContext, self::URL, false, $result);

        $this->assertSame($result, $cache->remote($activeContext, self::URL, false));
        $this->assertNull($cache->remote($activeContext, 'https://example.com/other', false));
        $this->assertSame(3, $cache->termDefinitions());
    }

    public function testAResultBelongsToOneActiveContext(): void
    {
        $cache = new ProcessedContextCache();
        $activeContext = new ActiveContext();
        $definition = self::definition();

        $cache->storeScoped($activeContext, $definition, false, true, self::processed(1));
        $cache->storeRemote($activeContext, self::URL, false, self::processed(1));

        $this->assertNull($cache->scoped(new ActiveContext(), $definition, false, true));
        $this->assertNull($cache->remote(new ActiveContext(), self::URL, false));
    }

    public function testAResultBelongsToOneTermDefinition(): void
    {
        $cache = new ProcessedContextCache();
        $activeContext = new ActiveContext();

        $cache->storeScoped($activeContext, self::definition(), false, true, self::processed(1));

        $this->assertNull($cache->scoped($activeContext, self::definition(), false, true));
    }

    public function testKeepsAResultForEachSetOfFlags(): void
    {
        $cache = new ProcessedContextCache();
        $activeContext = new ActiveContext();
        $definition = self::definition();
        $plain = self::processed(1);
        $overriding = self::processed(1);
        $notPropagated = self::processed(1);
        $both = self::processed(1);
        $remote = self::processed(1);
        $remoteOverriding = self::processed(1);

        $cache->storeScoped($activeContext, $definition, false, true, $plain);

        $this->assertNull($cache->scoped($activeContext, $definition, true, true));
        $this->assertNull($cache->scoped($activeContext, $definition, false, false));
        $this->assertNull($cache->scoped($activeContext, $definition, true, false));

        $cache->storeScoped($activeContext, $definition, true, true, $overriding);
        $cache->storeScoped($activeContext, $definition, false, false, $notPropagated);
        $cache->storeScoped($activeContext, $definition, true, false, $both);
        $cache->storeRemote($activeContext, self::URL, false, $remote);

        $this->assertNull($cache->remote($activeContext, self::URL, true));

        $cache->storeRemote($activeContext, self::URL, true, $remoteOverriding);

        $this->assertSame($plain, $cache->scoped($activeContext, $definition, false, true));
        $this->assertSame($overriding, $cache->scoped($activeContext, $definition, true, true));
        $this->assertSame($notPropagated, $cache->scoped($activeContext, $definition, false, false));
        $this->assertSame($both, $cache->scoped($activeContext, $definition, true, false));
        $this->assertSame($remote, $cache->remote($activeContext, self::URL, false));
        $this->assertSame($remoteOverriding, $cache->remote($activeContext, self::URL, true));
    }

    public function testHoldsTheTermDefinitionOfAScopedContext(): void
    {
        $cache = new ProcessedContextCache();
        $activeContext = new ActiveContext();
        $definition = self::definition();
        $reference = WeakReference::create($definition);

        $cache->storeScoped($activeContext, $definition, false, true, self::processed(1));
        unset($definition);

        $this->assertNotNull($reference->get());

        $cache->clear();

        $this->assertNull($reference->get());
    }

    public function testReleasesTheResultsOfAnActiveContextWithIt(): void
    {
        $cache = new ProcessedContextCache();
        $activeContext = new ActiveContext();
        $result = self::processed(1);
        $reference = WeakReference::create($result);

        $cache->storeRemote($activeContext, self::URL, false, $result);
        unset($result);

        $this->assertNotNull($reference->get());

        unset($activeContext);

        $this->assertNull($reference->get());
    }

    public function testClearReleasesAResultThatRefersToItsActiveContext(): void
    {
        gc_disable();

        try {
            $cache = new ProcessedContextCache();
            $activeContext = new ActiveContext();
            $reference = WeakReference::create($activeContext);

            $result = self::processed(1)->withPreviousContext($activeContext);

            $cache->storeRemote($activeContext, self::URL, false, $result);
            unset($activeContext, $result);

            $this->assertNotNull($reference->get());

            $cache->clear();

            $this->assertNull($reference->get());
        } finally {
            gc_enable();
        }
    }

    public function testClearEmptiesTheCache(): void
    {
        $cache = new ProcessedContextCache(2);
        $activeContext = new ActiveContext();
        $definition = self::definition();
        $result = self::processed(2);

        $cache->storeScoped($activeContext, $definition, false, true, $result);
        $cache->clear();

        $this->assertNull($cache->scoped($activeContext, $definition, false, true));
        $this->assertSame(0, $cache->termDefinitions());

        $cache->storeScoped($activeContext, $definition, false, true, $result);

        $this->assertSame($result, $cache->scoped($activeContext, $definition, false, true));
    }

    public function testStoresNothingThatWouldPassTheCap(): void
    {
        $cache = new ProcessedContextCache(3);
        $activeContext = new ActiveContext();
        $first = self::processed(2);
        $tooLarge = self::processed(2);
        $second = self::processed(1);

        $cache->storeRemote($activeContext, 'https://example.com/first', false, $first);
        $cache->storeRemote($activeContext, 'https://example.com/too-large', false, $tooLarge);

        $this->assertNull($cache->remote($activeContext, 'https://example.com/too-large', false));
        $this->assertSame(2, $cache->termDefinitions());

        $cache->storeRemote($activeContext, 'https://example.com/second', false, $second);
        $cache->storeScoped($activeContext, self::definition(), false, true, self::processed(1));
        $cache->storeRemote(new ActiveContext(), 'https://example.com/first', false, self::processed(1));

        $this->assertSame($first, $cache->remote($activeContext, 'https://example.com/first', false));
        $this->assertSame($second, $cache->remote($activeContext, 'https://example.com/second', false));
        $this->assertSame(3, $cache->termDefinitions());
    }

    public function testAResultWithNoTermDefinitionsIsStoredAtTheCap(): void
    {
        $cache = new ProcessedContextCache(1);
        $activeContext = new ActiveContext();
        $empty = self::processed(0);

        $cache->storeRemote($activeContext, 'https://example.com/first', false, self::processed(1));
        $cache->storeRemote($activeContext, 'https://example.com/empty', false, $empty);

        $this->assertSame($empty, $cache->remote($activeContext, 'https://example.com/empty', false));
    }

    public function testTheCapIsOneHundredThousandUnlessGiven(): void
    {
        $cache = new ProcessedContextCache();
        $activeContext = new ActiveContext();
        $atTheCap = self::processed(100_000);

        $cache->storeRemote($activeContext, 'https://example.com/first', false, $atTheCap);
        $cache->storeRemote($activeContext, 'https://example.com/second', false, self::processed(1));

        $this->assertSame($atTheCap, $cache->remote($activeContext, 'https://example.com/first', false));
        $this->assertNull($cache->remote($activeContext, 'https://example.com/second', false));
    }

    private static function definition(): TermDefinition
    {
        return new TermDefinition(iriMapping: 'https://example.com/ns#items', hasContext: true, context: self::URL);
    }

    /**
     * Returns an active context with the given number of term definitions
     */
    private static function processed(int $termDefinitions): ActiveContext
    {
        return new ActiveContext(
            $termDefinitions > 0
                ? array_fill(0, $termDefinitions, new TermDefinition(iriMapping: 'https://example.com/ns#term'))
                : [],
        );
    }
}
