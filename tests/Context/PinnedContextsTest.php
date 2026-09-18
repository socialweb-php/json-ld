<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\Context\ContextProcessor;
use SocialWeb\JsonLd\DocumentReader;
use SocialWeb\JsonLd\Limits;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\Rdf\JsonCanonicalizer;
use SocialWeb\Test\JsonLd\TestCase;

use function array_map;
use function basename;
use function count;
use function file_get_contents;
use function glob;
use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function sort;

use const JSON_THROW_ON_ERROR;

/**
 * Proves that the pinned resources, the manifest, the generated PHP files,
 * and the loader's table all describe the same nine contexts
 */
class PinnedContextsTest extends TestCase
{
    private const string RESOURCES = __DIR__ . '/../../resources/contexts';
    private const string BUNDLED = __DIR__ . '/../../src/Context/Bundled';

    public function testTheManifestListsEveryResourceAndEveryGeneratedFile(): void
    {
        $names = array_map(static fn (array $context): string => $context['name'], self::manifest());
        $resources = array_map(
            static fn (string $path): string => basename($path, '.jsonld'),
            glob(self::RESOURCES . '/*.jsonld') ?: [],
        );
        $generated = array_map(
            static fn (string $path): string => basename($path, '.php'),
            glob(self::BUNDLED . '/*.php') ?: [],
        );

        sort($names);
        sort($resources);
        sort($generated);

        $this->assertCount(9, $names);
        $this->assertSame($names, $resources);
        $this->assertSame($names, $generated);
    }

    /**
     * @param list<string> $aliases
     */
    #[DataProvider('contexts')]
    public function testTheResourceMatchesTheHashInTheManifest(
        string $name,
        string $url,
        array $aliases,
        string $sha256,
    ): void {
        $this->assertSame($sha256, hash('sha256', self::resource($name)));
    }

    /**
     * @param list<string> $aliases
     */
    #[DataProvider('contexts')]
    public function testTheGeneratedFileAgreesWithTheResource(
        string $name,
        string $url,
        array $aliases,
        string $sha256,
    ): void {
        $expected = json_decode(self::resource($name), flags: JSON_THROW_ON_ERROR);
        $generated = require self::BUNDLED . '/' . $name . '.php';

        $this->assertIsArray($generated);
        $this->assertSame(
            JsonCanonicalizer::canonicalize($expected),
            JsonCanonicalizer::canonicalize((new DocumentReader(new Limits()))->read($generated)),
        );
    }

    /**
     * @param list<string> $aliases
     */
    #[DataProvider('contexts')]
    public function testTheLoaderAnswersForTheUrlAndItsAliases(
        string $name,
        string $url,
        array $aliases,
        string $sha256,
    ): void {
        $loader = new BundledDocumentLoader();
        $generated = require self::BUNDLED . '/' . $name . '.php';

        $this->assertCount(1, $aliases);

        foreach ([$url, ...$aliases] as $address) {
            $loaded = $loader->load($address);

            $this->assertSame($address, $loaded->documentUrl);
            $this->assertSame($generated, $loaded->document);
        }
    }

    /**
     * @param list<string> $aliases
     */
    #[DataProvider('contexts')]
    public function testThePinnedContextIsValidInStrictMode(
        string $name,
        string $url,
        array $aliases,
        string $sha256,
    ): void {
        $processor = new ContextProcessor(new Options());
        $result = $processor->process(ActiveContext::initial(null), $url, null);

        $this->assertGreaterThan(0, count($result->termDefinitions));
    }

    /**
     * @return iterable<string, array{string, string, list<string>, string}>
     */
    public static function contexts(): iterable
    {
        foreach (self::manifest() as $context) {
            yield $context['name'] => [$context['name'], $context['url'], $context['aliases'], $context['sha256']];
        }
    }

    /**
     * @return list<array{name: string, url: string, aliases: list<string>, sha256: string}>
     */
    private static function manifest(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(self::RESOURCES . '/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $contexts = [];
        $entries = is_array($manifest) && is_array($manifest['contexts'] ?? null) ? $manifest['contexts'] : [];

        foreach ($entries as $entry) {
            if (
                !is_array($entry)
                || !is_string($entry['name'] ?? null)
                || !is_string($entry['url'] ?? null)
                || !is_array($entry['aliases'] ?? null)
                || !is_string($entry['sha256'] ?? null)
            ) {
                continue;
            }

            $aliases = [];

            foreach ($entry['aliases'] as $alias) {
                if (is_string($alias)) {
                    $aliases[] = $alias;
                }
            }

            $contexts[] = [
                'name' => $entry['name'],
                'url' => $entry['url'],
                'aliases' => $aliases,
                'sha256' => $entry['sha256'],
            ];
        }

        return $contexts;
    }

    private static function resource(string $name): string
    {
        return (string) file_get_contents(self::RESOURCES . '/' . $name . '.jsonld');
    }
}
