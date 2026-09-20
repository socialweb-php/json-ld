<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use SocialWeb\JsonLd\Rdf\JsonCanonicalizer;
use stdClass;

use function array_map;
use function get_object_vars;
use function is_array;
use function strcmp;
use function usort;

/**
 * JSON-LD object comparison, as the W3C suite defines it: the order of map
 * entries never matters, and the order of an array matters only when the
 * array is the value of `@list`
 *
 * The contents of a JSON literal are left as they are.
 */
final class JsonLdComparison
{
    /**
     * Returns the document as canonical JSON, after sorting every array whose
     * order does not matter, so that two documents are the same exactly when
     * the two strings are
     */
    public static function canonicalize(mixed $document): string
    {
        return JsonCanonicalizer::canonicalize(self::normalize($document, false));
    }

    private static function normalize(mixed $value, bool $ordered): mixed
    {
        if (is_array($value)) {
            $items = array_map(static fn (mixed $item): mixed => self::normalize($item, false), $value);

            if (!$ordered) {
                usort(
                    $items,
                    static fn (mixed $a, mixed $b): int => strcmp(
                        JsonCanonicalizer::canonicalize($a),
                        JsonCanonicalizer::canonicalize($b),
                    ),
                );
            }

            return $items;
        }

        if (!$value instanceof stdClass) {
            return $value;
        }

        $isJsonLiteral = ($value->{'@type'} ?? null) === '@json';
        $normalized = new stdClass();

        foreach (get_object_vars($value) as $key => $entry) {
            $normalized->{$key} = $isJsonLiteral && $key === '@value'
                ? $entry
                : self::normalize($entry, $key === '@list');
        }

        return $normalized;
    }
}
