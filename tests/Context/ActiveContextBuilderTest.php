<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\ActiveContextBuilder;
use SocialWeb\JsonLd\Context\TermDefinition;
use SocialWeb\Test\JsonLd\TestCase;

class ActiveContextBuilderTest extends TestCase
{
    public function testStartsWithWhatTheActiveContextHolds(): void
    {
        $name = new TermDefinition(iriMapping: 'https://example.com/name');
        $context = ActiveContext::initial('https://example.com/doc')
            ->withVocabularyMapping('https://example.com/vocab#')
            ->withTermDefinitions(['name' => $name]);
        $builder = new ActiveContextBuilder($context);

        $this->assertSame('https://example.com/doc', $builder->baseIri);
        $this->assertSame('https://example.com/vocab#', $builder->vocabularyMapping);
        $this->assertSame($name, $builder->termDefinition('name'));
        $this->assertNull($builder->termDefinition('other'));
    }

    public function testSetsAndRemovesTermDefinitionsInPlace(): void
    {
        $name = new TermDefinition(iriMapping: 'https://example.com/name');
        $builder = new ActiveContextBuilder(new ActiveContext());

        $builder->set('name', $name);

        $this->assertSame($name, $builder->termDefinition('name'));

        $builder->remove('name');
        $builder->remove('never defined');

        $this->assertNull($builder->termDefinition('name'));
    }

    public function testFindsATermMadeOfDigits(): void
    {
        $definition = new TermDefinition(iriMapping: 'https://example.com/123');
        $builder = new ActiveContextBuilder(new ActiveContext());

        $builder->set('123', $definition);

        $this->assertSame($definition, $builder->termDefinition('123'));
        $this->assertSame($definition, $builder->build()->termDefinition('123'));
    }

    public function testBuildsAnActiveContextThatKeepsEveryOtherPart(): void
    {
        $previous = new ActiveContext();
        $name = new TermDefinition(iriMapping: 'https://example.com/name');
        $context = ActiveContext::initial('https://example.com/doc')
            ->withBaseIri('https://example.com/base')
            ->withVocabularyMapping('https://example.com/vocab#')
            ->withDefaultLanguage('en')
            ->withDefaultBaseDirection('rtl')
            ->withPreviousContext($previous);
        $builder = new ActiveContextBuilder($context);

        $builder->set('name', $name);
        $built = $builder->build();

        $this->assertSame(['name' => $name], $built->termDefinitions);
        $this->assertSame('https://example.com/base', $built->baseIri);
        $this->assertSame('https://example.com/doc', $built->originalBaseUrl);
        $this->assertSame('https://example.com/vocab#', $built->vocabularyMapping);
        $this->assertSame('en', $built->defaultLanguage);
        $this->assertSame('rtl', $built->defaultBaseDirection);
        $this->assertSame($previous, $built->previousContext);
    }

    public function testNeverChangesTheActiveContextItWasMadeFromOrOneItHasBuilt(): void
    {
        $name = new TermDefinition(iriMapping: 'https://example.com/name');
        $context = (new ActiveContext())->withTermDefinitions(['name' => $name]);
        $builder = new ActiveContextBuilder($context);

        $builder->remove('name');
        $built = $builder->build();
        $builder->set('later', $name);

        $this->assertSame(['name' => $name], $context->termDefinitions);
        $this->assertSame([], $built->termDefinitions);
        $this->assertSame(['later' => $name], $builder->build()->termDefinitions);
    }

    public function testKeepsTheNumberOfProtectedTermDefinitionsUpToDate(): void
    {
        $open = new TermDefinition(iriMapping: 'https://example.com/open');
        $closed = new TermDefinition(iriMapping: 'ex:closed', protected: true);
        $builder = new ActiveContextBuilder((new ActiveContext())->withTermDefinitions(['first' => $closed]));

        $this->assertSame(1, $builder->build()->protectedTermDefinitions);

        // A protected term is set, and then replaced by another protected one.
        $builder->set('second', $closed);
        $builder->set('second', $closed);

        $this->assertSame(2, $builder->build()->protectedTermDefinitions);

        // A protected term is replaced by one that is not protected.
        $builder->set('first', $open);

        $this->assertSame(1, $builder->build()->protectedTermDefinitions);

        // A term that is not protected is set, and then replaced by a
        // protected one.
        $builder->set('third', $open);

        $this->assertSame(1, $builder->build()->protectedTermDefinitions);

        $builder->set('third', $closed);

        $this->assertSame(2, $builder->build()->protectedTermDefinitions);

        // A protected term, a term that is not protected, and a term that was
        // never defined are removed.
        $builder->remove('third');
        $builder->remove('first');
        $builder->remove('never defined');

        $this->assertSame(1, $builder->build()->protectedTermDefinitions);
        $this->assertTrue($builder->build()->hasProtectedTermDefinitions());

        $builder->remove('second');

        $this->assertSame(0, $builder->build()->protectedTermDefinitions);
        $this->assertFalse($builder->build()->hasProtectedTermDefinitions());
    }
}
