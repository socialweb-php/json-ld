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

namespace SocialWeb\JsonLd\Rdf;

use function abs;
use function fmod;
use function is_int;
use function preg_replace;
use function sprintf;

/**
 * The canonical lexical forms of JSON-LD 1.1 Processing Algorithms and API
 * section 8.6, "Data Round Tripping"
 *
 * These are the strings that native JSON numbers and booleans become when a
 * document is converted to RDF.
 *
 * @internal
 */
final class LexicalForms
{
    /**
     * The magnitude at or above which a number is a double rather than an
     * integer, per section 8.2
     */
    private const float DOUBLE_THRESHOLD = 1.0e21;

    /**
     * Returns true if the number must be an `xsd:double`, i.e., it has a
     * non-zero fractional part or a magnitude of 10^21 or more
     *
     * Every PHP integer is below the threshold and has no fractional part,
     * so only a float can be a double. A float with no fractional part below
     * the threshold, such as `5.0`, is an integer, as it is to a JavaScript
     * processor.
     */
    public static function isDouble(int | float $value): bool
    {
        return fmod($value, 1.0) !== 0.0 || abs($value) >= self::DOUBLE_THRESHOLD;
    }

    /**
     * Returns the canonical form of an `xsd:integer`, i.e., decimal digits with
     * an optional leading minus sign and no leading zeros
     *
     * A float is accepted only when it has no fractional part; the caller
     * checks with {@see isDouble()} first. PHP's `%f` conversion drops the
     * sign of negative zero, so `-0.0` is written as `0`.
     */
    public static function integer(int | float $value): string
    {
        return is_int($value) ? (string) $value : sprintf('%.0f', $value);
    }

    /**
     * Returns the canonical form of an `xsd:double`, i.e., a significand with
     * one non-zero digit before the point and no trailing zeros, the letter
     * `E`, and an exponent with no plus sign or leading zeros; zero is `0.0E0`
     *
     * The significand is rounded to 15 digits after the point, as section 8.6
     * requires. PHP's `%E` conversion gives the digits and drops the sign of
     * negative zero; the regular expression is the one the specification
     * shows for JavaScript.
     */
    public static function double(int | float $value): string
    {
        return (string) preg_replace('/(\d)0*E\+?/', '$1E', sprintf('%.15E', (float) $value));
    }

    /**
     * Returns the canonical form of an `xsd:boolean`
     */
    public static function boolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
