<?php

/**
 * This file is part of socialweb/json-ld
 *
 * socialweb/json-ld is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Lesser General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.
 *
 * socialweb/json-ld is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public License
 * for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with socialweb/json-ld. If not, see <https://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace SocialWeb\JsonLd;

use function array_pop;
use function explode;
use function implode;
use function preg_match;
use function str_ends_with;
use function strrpos;
use function substr;

use const PREG_UNMATCHED_AS_NULL;

/**
 * Resolves an IRI reference against a base IRI
 *
 * This is the basic algorithm of RFC 3986 section 5.2, including the
 * dot-segment removal of section 5.2.4. JSON-LD 1.1 Processing Algorithms and
 * API section 4.3 requires exactly this. Neither syntax-based nor scheme-based
 * normalization is performed, so the result keeps the case, percent-encoding,
 * and default ports of its inputs. The "strict" reading of section 5.2.2
 * applies: a reference with a scheme is returned as is, even when the scheme
 * matches the base's.
 *
 * @internal
 */
final class IriResolver
{
    /**
     * The regular expression of RFC 3986 appendix B, which splits a reference
     * into scheme, authority, path, query, and fragment
     */
    private const string COMPONENTS = '~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~s';

    /**
     * Returns the target IRI of the reference against the base
     *
     * @param string $reference An IRI reference, absolute or relative
     * @param string $base An absolute IRI; a fragment on the base is ignored,
     *     as RFC 3986 section 5.1 prescribes
     */
    public static function resolve(string $reference, string $base): string
    {
        [$scheme, $authority, $path, $query, $fragment] = self::parse($reference);

        if ($scheme !== null) {
            return self::recompose($scheme, $authority, self::removeDotSegments($path), $query, $fragment);
        }

        [$baseScheme, $baseAuthority, $basePath, $baseQuery] = self::parse($base);

        if ($authority !== null) {
            return self::recompose($baseScheme, $authority, self::removeDotSegments($path), $query, $fragment);
        }

        if ($path === '') {
            return self::recompose($baseScheme, $baseAuthority, $basePath, $query ?? $baseQuery, $fragment);
        }

        if ($path[0] !== '/') {
            $path = self::merge($baseAuthority, $basePath, $path);
        }

        return self::recompose($baseScheme, $baseAuthority, self::removeDotSegments($path), $query, $fragment);
    }

    /**
     * Returns the components of a reference: scheme, authority, path, query,
     * and fragment, with null for each component that is absent
     *
     * @return array{string | null, string | null, string, string | null, string | null}
     */
    private static function parse(string $reference): array
    {
        preg_match(self::COMPONENTS, $reference, $matches, PREG_UNMATCHED_AS_NULL);

        return [
            $matches[1] ?? null,
            $matches[2] ?? null,
            $matches[3] ?? '',
            $matches[4] ?? null,
            $matches[5] ?? null,
        ];
    }

    /**
     * Merges a relative path with the base path, per RFC 3986 section 5.2.3
     */
    private static function merge(?string $baseAuthority, string $basePath, string $path): string
    {
        if ($baseAuthority !== null && $basePath === '') {
            return '/' . $path;
        }

        $lastSlash = strrpos($basePath, '/');

        return $lastSlash === false ? $path : substr($basePath, 0, $lastSlash + 1) . $path;
    }

    /**
     * Removes `.` and `..` segments, per RFC 3986 section 5.2.4
     *
     * The output is built as a list of segments rather than by the
     * specification's string-buffer steps, which gives the same result.
     */
    private static function removeDotSegments(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $absolute = $path[0] === '/';
        $output = [];

        foreach (explode('/', $absolute ? substr($path, 1) : $path) as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($output);

                continue;
            }

            $output[] = $segment;
        }

        // A path whose last segment is "." or ".." keeps its trailing slash.
        if (str_ends_with($path, '/.') || str_ends_with($path, '/..')) {
            $output[] = '';
        }

        return ($absolute ? '/' : '') . implode('/', $output);
    }

    private static function recompose(
        ?string $scheme,
        ?string $authority,
        string $path,
        ?string $query,
        ?string $fragment,
    ): string {
        $result = $scheme === null ? '' : $scheme . ':';

        if ($authority !== null) {
            $result .= '//' . $authority;
        }

        $result .= $path;

        if ($query !== null) {
            $result .= '?' . $query;
        }

        if ($fragment !== null) {
            $result .= '#' . $fragment;
        }

        return $result;
    }
}
