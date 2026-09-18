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
 * The keywords of JSON-LD 1.1, and the test for strings that look like one
 *
 * @internal
 */
final class Keywords
{
    /**
     * The keywords listed in JSON-LD 1.1 section 1.7, "Syntax Tokens and
     * Keywords"
     */
    private const array KEYWORDS = [
        '@base' => true,
        '@container' => true,
        '@context' => true,
        '@direction' => true,
        '@graph' => true,
        '@id' => true,
        '@import' => true,
        '@included' => true,
        '@index' => true,
        '@json' => true,
        '@language' => true,
        '@list' => true,
        '@nest' => true,
        '@none' => true,
        '@prefix' => true,
        '@propagate' => true,
        '@protected' => true,
        '@reverse' => true,
        '@set' => true,
        '@type' => true,
        '@value' => true,
        '@version' => true,
        '@vocab' => true,
    ];

    /**
     * The ABNF rule `"@"1*ALPHA` of RFC 5234, which the algorithms use to
     * recognize strings reserved for future keywords
     */
    private const string KEYWORD_FORM = '/\A@[A-Za-z]+\z/';

    /**
     * Returns true if the value is a JSON-LD 1.1 keyword
     */
    public static function isKeyword(string $value): bool
    {
        return isset(self::KEYWORDS[$value]);
    }

    /**
     * Returns true if the value has the form of a keyword, regardless of
     * whether it is one
     */
    public static function hasKeywordForm(string $value): bool
    {
        return preg_match(self::KEYWORD_FORM, $value) === 1;
    }
}
