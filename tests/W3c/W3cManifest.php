<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use RuntimeException;

use function file_get_contents;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Reads the W3C JSON-LD API test manifests vendored under
 * tests/fixtures/json-ld-api
 *
 * The manifests are JSON-LD, but their structure is fixed, so they are read
 * as plain JSON. Entries that test JSON-LD 1.0 behavior that JSON-LD 1.1
 * changed, marked with a `specVersion` of `json-ld-1.0`, are left out, as the
 * reference processors leave them out.
 */
final class W3cManifest
{
    public const string FIXTURES = __DIR__ . '/../fixtures/json-ld-api';

    /**
     * The URL the suite is published at, which every manifest gives as its
     * `baseIri`
     */
    public const string BASE_IRI = 'https://w3c.github.io/json-ld-api/tests/';

    public const string EXPAND = 'expand-manifest.jsonld';

    public const string TO_RDF = 'toRdf-manifest.jsonld';

    public const string POSITIVE = 'jld:PositiveEvaluationTest';

    public const string NEGATIVE = 'jld:NegativeEvaluationTest';

    public const string POSITIVE_SYNTAX = 'jld:PositiveSyntaxTest';

    /**
     * Yields every entry of the given type, by identifier
     *
     * @param self::EXPAND | self::TO_RDF $manifest
     * @param self::POSITIVE | self::NEGATIVE | self::POSITIVE_SYNTAX $type
     *
     * @return iterable<string, array{W3cEntry}>
     */
    public static function entries(string $manifest, string $type): iterable
    {
        $document = json_decode(self::read(self::FIXTURES . '/' . $manifest), true, 512, JSON_THROW_ON_ERROR);
        $sequence = is_array($document) ? $document['sequence'] ?? null : null;

        if (!is_array($document) || ($document['baseIri'] ?? null) !== self::BASE_IRI || !is_array($sequence)) {
            throw new RuntimeException(sprintf('%s is not a manifest of the JSON-LD API test suite', $manifest));
        }

        foreach ($sequence as $entry) {
            if (!is_array($entry) || !in_array($type, (array) ($entry['@type'] ?? []), true)) {
                continue;
            }

            /** @var array<string, mixed> $option */
            $option = is_array($entry['option'] ?? null) ? $entry['option'] : [];

            if (($option['specVersion'] ?? null) === 'json-ld-1.0') {
                continue;
            }

            $id = self::string($entry, '@id');
            $input = self::string($entry, 'input');

            yield $id => [
                new W3cEntry(
                    id: $id,
                    inputUrl: self::BASE_IRI . $input,
                    input: self::FIXTURES . '/' . $input,
                    expect: isset($entry['expect']) ? self::FIXTURES . '/' . self::string($entry, 'expect') : null,
                    expectErrorCode: isset($entry['expectErrorCode']) ? self::string($entry, 'expectErrorCode') : null,
                    option: $option,
                ),
            ];
        }
    }

    /**
     * Reads a fixture file, failing loudly if it is missing
     */
    public static function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read fixture %s', $path));
        }

        return $contents;
    }

    /**
     * @param array<mixed> $entry
     */
    private static function string(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('A manifest entry has no string value for "%s"', $key));
        }

        return $value;
    }
}
