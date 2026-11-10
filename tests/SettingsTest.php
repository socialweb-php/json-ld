<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\ProcessingMode;
use SocialWeb\JsonLd\RdfDirection;
use SocialWeb\JsonLd\Settings;

final class SettingsTest extends TestCase
{
    public function testHasTheDocumentedDefaults(): void
    {
        $settings = new Settings();

        $this->assertNull($settings->base);
        $this->assertNull($settings->expandContext);
        $this->assertSame(ProcessingMode::JsonLd11, $settings->processingMode);
        $this->assertNull($settings->rdfDirection);
        $this->assertTrue($settings->strict);
        $this->assertSame(128, $settings->maxDepth);
        $this->assertSame(100_000, $settings->maxValues);
        $this->assertSame(1_000_000, $settings->maxContextOperations);
        $this->assertFalse($settings->forbidNamedGraphs);
        $this->assertFalse($settings->forbidIncludedBlocks);
        $this->assertFalse($settings->forbidReverseProperties);
        $this->assertFalse($settings->requireSingleTopLevelNode);
        $this->assertSame(
            'https://www.w3.org/ns/activitystreams',
            $settings->documentLoader->load('https://www.w3.org/ns/activitystreams')->documentUrl,
        );
    }

    public function testAcceptsEverySettingByName(): void
    {
        $context = ['@vocab' => 'https://example.com/vocab#'];
        $loader = new BundledDocumentLoader();

        $settings = new Settings(
            base: 'https://example.com/base/',
            expandContext: $context,
            processingMode: ProcessingMode::JsonLd10,
            rdfDirection: RdfDirection::I18nDatatype,
            strict: false,
            documentLoader: $loader,
            maxDepth: 2,
            maxValues: 3,
            maxContextOperations: 4,
            forbidNamedGraphs: true,
            forbidIncludedBlocks: true,
            forbidReverseProperties: true,
            requireSingleTopLevelNode: true,
        );

        $this->assertSame('https://example.com/base/', $settings->base);
        $this->assertSame($context, $settings->expandContext);
        $this->assertSame(ProcessingMode::JsonLd10, $settings->processingMode);
        $this->assertSame(RdfDirection::I18nDatatype, $settings->rdfDirection);
        $this->assertFalse($settings->strict);
        $this->assertSame($loader, $settings->documentLoader);
        $this->assertSame(2, $settings->maxDepth);
        $this->assertSame(3, $settings->maxValues);
        $this->assertSame(4, $settings->maxContextOperations);
        $this->assertTrue($settings->forbidNamedGraphs);
        $this->assertTrue($settings->forbidIncludedBlocks);
        $this->assertTrue($settings->forbidReverseProperties);
        $this->assertTrue($settings->requireSingleTopLevelNode);
    }

    public function testTurnsOnEachRestrictionByItself(): void
    {
        $flags = static fn (Settings $settings): array => [
            $settings->forbidNamedGraphs,
            $settings->forbidIncludedBlocks,
            $settings->forbidReverseProperties,
            $settings->requireSingleTopLevelNode,
        ];

        $this->assertSame([true, false, false, false], $flags(new Settings(forbidNamedGraphs: true)));
        $this->assertSame([false, true, false, false], $flags(new Settings(forbidIncludedBlocks: true)));
        $this->assertSame([false, false, true, false], $flags(new Settings(forbidReverseProperties: true)));
        $this->assertSame([false, false, false, true], $flags(new Settings(requireSingleTopLevelNode: true)));
    }

    public function testAcceptsAContextUrlAsTheExpandContext(): void
    {
        $settings = new Settings(expandContext: 'https://example.com/context');

        $this->assertSame('https://example.com/context', $settings->expandContext);
    }

    public function testAcceptsOneAsTheSmallestLimit(): void
    {
        $settings = new Settings(maxDepth: 1, maxValues: 1, maxContextOperations: 1);

        $this->assertSame(1, $settings->maxDepth);
        $this->assertSame(1, $settings->maxValues);
        $this->assertSame(1, $settings->maxContextOperations);
    }

    public function testRejectsADepthBelowOne(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('maxDepth must be at least 1; 0 given');

        new Settings(maxDepth: 0);
    }

    public function testRejectsAValueCountBelowOne(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('maxValues must be at least 1; -1 given');

        new Settings(maxValues: -1);
    }

    public function testRejectsAContextOperationCountBelowOne(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('maxContextOperations must be at least 1; 0 given');

        new Settings(maxContextOperations: 0);
    }

    #[DataProvider('invalidBases')]
    public function testRejectsABaseThatIsNotAWellFormedAbsoluteIri(string $base): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains(
            'The base must be a well-formed absolute IRI; "' . $base . '" given',
        );

        new Settings(base: $base);
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
        yield 'more than one number sign' => ['http://example.com/a#b#c'];
    }
}
