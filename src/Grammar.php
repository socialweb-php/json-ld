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

use function preg_match;

/**
 * The productions that decide whether a string is a well-formed IRI,
 * language tag, or blank node identifier
 *
 * "Well-formed" here means what the socialweb/rdf term constructors accept,
 * so that the algorithms' drop points and the RDF model agree. The patterns
 * are copied from that package's internal N-Quads grammar, including RDF 1.1
 * erratum 30, which removed `:` from the characters a blank node label may
 * contain.
 *
 * @internal
 */
final class Grammar
{
    /**
     * Matches the scheme of an absolute IRI, per RFC 3986 section 3.1
     */
    public const string SCHEME = '/\A[A-Za-z][A-Za-z0-9+.\-]*:/';

    /**
     * Matches any character that the N-Quads IRIREF production forbids
     * between `<` and `>`
     */
    public const string IRIREF_FORBIDDEN = '/[\x00-\x20<>"{}|^`\\\\]/u';

    /**
     * The tag part of the N-Quads LANGTAG production, without the leading `@`
     */
    public const string LANGTAG = '/\A[a-zA-Z]+(?:-[a-zA-Z0-9]+)*\z/';

    private const string PN_CHARS_BASE = 'A-Za-z'
        . '\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}\x{0370}-\x{037D}'
        . '\x{037F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}'
        . '\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}';

    private const string PN_CHARS_U = self::PN_CHARS_BASE . '_';

    private const string PN_CHARS = self::PN_CHARS_U . '\-0-9\x{00B7}\x{0300}-\x{036F}\x{203F}-\x{2040}';

    /**
     * The label part of the N-Quads BLANK_NODE_LABEL production, without the
     * leading `_:`
     */
    public const string BLANK_NODE_LABEL = '/\A[' . self::PN_CHARS_U . '0-9]'
        . '(?:[' . self::PN_CHARS . '.]*[' . self::PN_CHARS . '])?\z/u';

    /**
     * Returns true if the value has a scheme, as RFC 3986 defines one
     */
    public static function isAbsoluteIri(string $value): bool
    {
        return preg_match(self::SCHEME, $value) === 1;
    }

    /**
     * Returns true if the value is an absolute IRI that socialweb/rdf's Iri
     * would accept: valid UTF-8, with a scheme, and free of the characters
     * the N-Quads IRIREF production forbids
     *
     * The forbidden-character pattern carries the `u` modifier, so it fails
     * to match on a value that is not valid UTF-8. That is the UTF-8 check.
     */
    public static function isWellFormedIri(string $value): bool
    {
        return preg_match(self::SCHEME, $value) === 1 && preg_match(self::IRIREF_FORBIDDEN, $value) === 0;
    }

    /**
     * Returns true if the tag matches the N-Quads LANGTAG production, which is
     * what socialweb/rdf's Literal requires of a language tag
     */
    public static function isWellFormedLanguageTag(string $tag): bool
    {
        return preg_match(self::LANGTAG, $tag) === 1;
    }

    /**
     * Returns true if the identifier, without its `_:` prefix, matches the
     * N-Quads BLANK_NODE_LABEL production, which is what socialweb/rdf's
     * BlankNode requires
     */
    public static function isWellFormedBlankNodeIdentifier(string $identifier): bool
    {
        return preg_match(self::BLANK_NODE_LABEL, $identifier) === 1;
    }
}
