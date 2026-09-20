<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\TermDefinition;
use SocialWeb\Test\JsonLd\TestCase;

class ActiveContextTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $context = new ActiveContext();

        $this->assertSame([], $context->termDefinitions);
        $this->assertNull($context->baseIri);
        $this->assertNull($context->originalBaseUrl);
        $this->assertNull($context->vocabularyMapping);
        $this->assertNull($context->defaultLanguage);
        $this->assertNull($context->defaultBaseDirection);
        $this->assertNull($context->previousContext);
        $this->assertFalse($context->hasProtectedTermDefinitions());
    }

    public function testAnInitialContextUsesTheBaseForBothBaseIriAndOriginalBaseUrl(): void
    {
        $context = ActiveContext::initial('https://example.com/doc');

        $this->assertSame('https://example.com/doc', $context->baseIri);
        $this->assertSame('https://example.com/doc', $context->originalBaseUrl);
        $this->assertSame([], $context->termDefinitions);
        $this->assertNull(ActiveContext::initial(null)->baseIri);
        $this->assertNull(ActiveContext::initial(null)->originalBaseUrl);
    }

    public function testReplacesTheTermDefinitionsWithoutChangingTheOriginal(): void
    {
        $name = new TermDefinition(iriMapping: 'https://example.com/name');
        $empty = ActiveContext::initial('https://example.com/doc');
        $one = $empty->withTermDefinitions(['name' => $name]);
        $none = $one->withTermDefinitions([]);

        $this->assertNull($empty->termDefinition('name'));
        $this->assertSame($name, $one->termDefinition('name'));
        $this->assertSame(['name' => $name], $one->termDefinitions);
        $this->assertNull($none->termDefinition('name'));
        $this->assertSame('https://example.com/doc', $one->baseIri);
        $this->assertSame('https://example.com/doc', $none->originalBaseUrl);
    }

    public function testFindsATermMadeOfDigits(): void
    {
        $definition = new TermDefinition(iriMapping: 'https://example.com/123');
        $context = (new ActiveContext())->withTermDefinitions(['123' => $definition]);

        $this->assertSame($definition, $context->termDefinition('123'));
        $this->assertNull($context->termDefinition('124'));
    }

    public function testKnowsWhetherAnyTermIsProtected(): void
    {
        $open = new TermDefinition(iriMapping: 'https://example.com/open');
        $context = (new ActiveContext())->withTermDefinitions(['open' => $open]);

        $this->assertFalse($context->hasProtectedTermDefinitions());

        $context = $context->withTermDefinitions([
            'open' => $open,
            'closed' => new TermDefinition(iriMapping: 'ex:closed', protected: true),
            'other' => new TermDefinition(iriMapping: 'https://example.com/other'),
        ]);

        $this->assertTrue($context->hasProtectedTermDefinitions());
    }

    public function testEachWithMethodChangesOnePartAndKeepsTheRest(): void
    {
        $previous = new ActiveContext();
        $original = ActiveContext::initial('https://example.com/doc');
        $changed = $original
            ->withBaseIri('https://example.com/base')
            ->withVocabularyMapping('https://example.com/vocab#')
            ->withDefaultLanguage('en')
            ->withDefaultBaseDirection('rtl')
            ->withPreviousContext($previous);

        $this->assertSame('https://example.com/base', $changed->baseIri);
        $this->assertSame('https://example.com/doc', $changed->originalBaseUrl);
        $this->assertSame('https://example.com/vocab#', $changed->vocabularyMapping);
        $this->assertSame('en', $changed->defaultLanguage);
        $this->assertSame('rtl', $changed->defaultBaseDirection);
        $this->assertSame($previous, $changed->previousContext);

        $this->assertSame('https://example.com/doc', $original->baseIri);
        $this->assertNull($original->vocabularyMapping);
        $this->assertNull($original->defaultLanguage);
        $this->assertNull($original->defaultBaseDirection);
        $this->assertNull($original->previousContext);

        $cleared = $changed
            ->withBaseIri(null)
            ->withVocabularyMapping(null)
            ->withDefaultLanguage(null)
            ->withDefaultBaseDirection(null)
            ->withPreviousContext(null);

        $this->assertNull($cleared->baseIri);
        $this->assertNull($cleared->vocabularyMapping);
        $this->assertNull($cleared->defaultLanguage);
        $this->assertNull($cleared->defaultBaseDirection);
        $this->assertNull($cleared->previousContext);
        $this->assertSame('https://example.com/doc', $cleared->originalBaseUrl);
    }
}
