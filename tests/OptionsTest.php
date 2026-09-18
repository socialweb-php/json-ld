<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Limits;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\ProcessingMode;
use SocialWeb\JsonLd\RdfDirection;
use SocialWeb\JsonLd\Restrictions;

class OptionsTest extends TestCase
{
    public function testHasTheDocumentedDefaults(): void
    {
        $options = new Options();

        $this->assertNull($options->base);
        $this->assertNull($options->expandContext);
        $this->assertSame(ProcessingMode::JsonLd11, $options->processingMode);
        $this->assertNull($options->rdfDirection);
        $this->assertTrue($options->strict);
        $this->assertSame(128, $options->limits->maxDepth);
        $this->assertSame(100_000, $options->limits->maxValues);
        $this->assertFalse($options->restrictions->forbidNamedGraphs);
        $this->assertFalse($options->restrictions->forbidIncludedBlocks);
        $this->assertFalse($options->restrictions->forbidReverseProperties);
        $this->assertFalse($options->restrictions->requireSingleTopLevelNode);
    }

    public function testAcceptsEveryOptionByName(): void
    {
        $limits = new Limits(maxDepth: 4, maxValues: 40);
        $restrictions = Restrictions::all();
        $context = ['@vocab' => 'https://example.com/vocab#'];

        $options = new Options(
            base: 'https://example.com/base/',
            expandContext: $context,
            processingMode: ProcessingMode::JsonLd10,
            rdfDirection: RdfDirection::I18nDatatype,
            strict: false,
            limits: $limits,
            restrictions: $restrictions,
        );

        $this->assertSame('https://example.com/base/', $options->base);
        $this->assertSame($context, $options->expandContext);
        $this->assertSame(ProcessingMode::JsonLd10, $options->processingMode);
        $this->assertSame(RdfDirection::I18nDatatype, $options->rdfDirection);
        $this->assertFalse($options->strict);
        $this->assertSame($limits, $options->limits);
        $this->assertSame($restrictions, $options->restrictions);
    }

    public function testAcceptsAContextUrlAsTheExpandContext(): void
    {
        $options = new Options(expandContext: 'https://example.com/context');

        $this->assertSame('https://example.com/context', $options->expandContext);
    }

    #[DataProvider('invalidBases')]
    public function testRejectsABaseThatIsNotAWellFormedAbsoluteIri(string $base): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('The base must be a well-formed absolute IRI');

        new Options(base: $base);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBases(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['base/'];
        yield 'absolute path' => ['/base/'];
        yield 'space' => ['https://example.com/a b/'];
    }
}
