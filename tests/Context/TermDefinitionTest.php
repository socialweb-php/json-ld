<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\TermDefinition;
use SocialWeb\Test\JsonLd\TestCase;

use function json_decode;

class TermDefinitionTest extends TestCase
{
    public function testStartsWithNothingSet(): void
    {
        $definition = new TermDefinition();

        $this->assertNull($definition->iriMapping);
        $this->assertFalse($definition->prefix);
        $this->assertFalse($definition->protected);
        $this->assertFalse($definition->reverse);
        $this->assertNull($definition->baseUrl);
        $this->assertFalse($definition->hasContext);
        $this->assertNull($definition->context);
        $this->assertSame([], $definition->containerMapping);
        $this->assertFalse($definition->hasDirectionMapping);
        $this->assertNull($definition->directionMapping);
        $this->assertNull($definition->indexMapping);
        $this->assertFalse($definition->hasLanguageMapping);
        $this->assertNull($definition->languageMapping);
        $this->assertNull($definition->nestValue);
        $this->assertNull($definition->typeMapping);
    }

    public function testKnowsWhichContainersItHas(): void
    {
        $definition = new TermDefinition(containerMapping: ['@index', '@set']);

        $this->assertTrue($definition->hasContainer('@set'));
        $this->assertTrue($definition->hasContainer('@index'));
        $this->assertFalse($definition->hasContainer('@list'));
        $this->assertFalse((new TermDefinition())->hasContainer('@set'));
    }

    public function testIsTheSameAsItselfWhateverTheProtectedFlag(): void
    {
        $definition = self::full();

        $this->assertTrue($definition->equalsExceptProtected(self::full()));
        $this->assertTrue($definition->equalsExceptProtected(self::full(protected: true)));
    }

    public function testComparesScopedContextsAsJson(): void
    {
        $one = new TermDefinition(hasContext: true, context: json_decode('{"a": "ex:a", "b": {"@id": "ex:b"}}'));
        $two = new TermDefinition(hasContext: true, context: json_decode('{"b": {"@id": "ex:b"}, "a": "ex:a"}'));
        $three = new TermDefinition(hasContext: true, context: json_decode('{"a": "ex:a", "b": {"@id": "ex:c"}}'));

        $this->assertTrue($one->equalsExceptProtected($two));
        $this->assertFalse($one->equalsExceptProtected($three));
    }

    #[DataProvider('differences')]
    public function testDiffersWhenAnyOtherPartDiffers(TermDefinition $different): void
    {
        $this->assertFalse(self::full()->equalsExceptProtected($different));
        $this->assertFalse($different->equalsExceptProtected(self::full()));
    }

    /**
     * @return iterable<string, array{TermDefinition}>
     */
    public static function differences(): iterable
    {
        yield 'IRI mapping' => [self::full(iriMapping: 'https://example.com/other')];
        yield 'no IRI mapping' => [self::full(iriMapping: null)];
        yield 'prefix flag' => [self::full(prefix: false)];
        yield 'reverse flag' => [self::full(reverse: false)];
        yield 'base URL' => [self::full(baseUrl: 'https://example.com/other')];
        yield 'scoped context present' => [self::full(hasContext: false)];
        yield 'scoped context' => [self::full(context: 'https://example.com/other')];
        yield 'container mapping' => [self::full(containerMapping: ['@set'])];
        yield 'direction mapping present' => [self::full(hasDirectionMapping: false)];
        yield 'direction mapping' => [self::full(directionMapping: 'ltr')];
        yield 'index mapping' => [self::full(indexMapping: 'other')];
        yield 'language mapping present' => [self::full(hasLanguageMapping: false)];
        yield 'language mapping' => [self::full(languageMapping: 'fr')];
        yield 'nest value' => [self::full(nestValue: 'other')];
        yield 'type mapping' => [self::full(typeMapping: '@vocab')];
    }

    /**
     * @param list<string> $containerMapping
     */
    private static function full(
        ?string $iriMapping = 'https://example.com/term',
        bool $prefix = true,
        bool $protected = false,
        bool $reverse = true,
        ?string $baseUrl = 'https://example.com/context',
        bool $hasContext = true,
        mixed $context = 'https://example.com/scoped',
        array $containerMapping = ['@index', '@set'],
        bool $hasDirectionMapping = true,
        ?string $directionMapping = 'rtl',
        ?string $indexMapping = 'index',
        bool $hasLanguageMapping = true,
        ?string $languageMapping = 'en',
        ?string $nestValue = '@nest',
        ?string $typeMapping = '@id',
    ): TermDefinition {
        return new TermDefinition(
            $iriMapping,
            $prefix,
            $protected,
            $reverse,
            $baseUrl,
            $hasContext,
            $context,
            $containerMapping,
            $hasDirectionMapping,
            $directionMapping,
            $indexMapping,
            $hasLanguageMapping,
            $languageMapping,
            $nestValue,
            $typeMapping,
        );
    }
}
