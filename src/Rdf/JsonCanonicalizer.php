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

use JsonException;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Utf16;
use stdClass;

use function abs;
use function array_is_list;
use function array_keys;
use function array_map;
use function explode;
use function get_debug_type;
use function get_object_vars;
use function implode;
use function is_array;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function ksort;
use function sprintf;
use function str_repeat;
use function str_replace;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_LINE_TERMINATORS;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

/**
 * Serializes a JSON value in the canonical form of RFC 8785, the JSON
 * Canonicalization Scheme
 *
 * This is the lexical form of an `rdf:JSON` literal. JSON-LD 1.1 section 10.2
 * describes the same form: no whitespace, object keys in sorted order, strings
 * escaped as ECMAScript's `JSON.stringify` escapes them, and numbers as
 * ECMAScript's `Number::toString` writes them. Where the two could differ, on
 * the unit in which keys are sorted by, this class follows RFC 8785 and sorts
 * by UTF-16 code units.
 *
 * @internal
 */
final class JsonCanonicalizer
{
    /**
     * Returns the canonical JSON text of a value in the library's internal
     * form: `stdClass` for objects, lists for arrays, and PHP scalars
     *
     * An associative array is treated as an object for callers that build
     * values by hand.
     *
     * @throws InvalidArgument if the value holds a type JSON cannot represent,
     *     a non-finite number, or a string that is not valid UTF-8
     */
    public static function canonicalize(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value === true) {
            return 'true';
        }

        if ($value === false) {
            return 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return self::number($value);
        }

        if (is_string($value)) {
            return self::string($value);
        }

        if (is_array($value) && array_is_list($value)) {
            return '[' . implode(',', array_map(self::canonicalize(...), $value)) . ']';
        }

        if (is_array($value) || $value instanceof stdClass) {
            return self::object($value instanceof stdClass ? get_object_vars($value) : $value);
        }

        throw new InvalidArgument(sprintf('JSON cannot represent a value of type %s', get_debug_type($value)));
    }

    /**
     * @param array<mixed> $entries
     */
    private static function object(array $entries): string
    {
        // Keyed by the UTF-16 big-endian encoding, which is unique per key, so
        // that sorting the keys of this array in binary order sorts the
        // object's keys by UTF-16 code units, and each key is encoded once.
        $keys = [];

        foreach (array_keys($entries) as $key) {
            $keys[Utf16::encode((string) $key)] = (string) $key;
        }

        ksort($keys, SORT_STRING);

        $members = [];

        foreach ($keys as $key) {
            $members[] = self::string($key) . ':' . self::canonicalize($entries[$key]);
        }

        return '{' . implode(',', $members) . '}';
    }

    /**
     * Escapes a string as ECMAScript's `JSON.stringify` does: only `"`, `\`,
     * and the control characters U+0000 through U+001F are escaped, the
     * five with short forms as `\b`, `\t`, `\n`, `\f`, and `\r` and the rest
     * with a lowercase four-digit escape
     *
     * @throws InvalidArgument if the string is not valid UTF-8
     */
    private static function string(string $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgument('JSON cannot represent a string that is not valid UTF-8', 0, $exception);
        }
    }

    /**
     * Formats a float as ECMAScript's `Number::toString` does: the shortest
     * decimal digits that round-trip, laid out per ECMA-262 section 6.1.6.1.20
     *
     * A double never needs more than 17 significant digits, so the digit
     * count k is at most 17, and the boundary n = 21 of the fixed-notation
     * branches is reached only by the first of them.
     *
     * @throws InvalidArgument if the number is not finite
     */
    private static function number(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidArgument('JSON cannot represent a number that is not finite');
        }

        // Zero and negative zero both have the digit string "0" and no sign,
        // because negative zero is not less than zero.
        $sign = $value < 0 ? '-' : '';
        [$digits, $exponent] = self::shortestDigits(abs($value));

        // ECMA-262 names the digit count k and the decimal exponent n, where
        // the value is 0.digits × 10^n.
        $k = strlen($digits);
        $n = $exponent + 1;

        if ($k <= $n && $n <= 21) {
            return $sign . $digits . str_repeat('0', $n - $k);
        }

        if (0 < $n && $n <= 21) {
            return $sign . substr($digits, 0, $n) . '.' . substr($digits, $n);
        }

        if (-6 < $n && $n <= 0) {
            return $sign . '0.' . str_repeat('0', -$n) . $digits;
        }

        $exponent = $n - 1;
        $exponentText = 'e' . ($exponent < 0 ? '-' : '+') . abs($exponent);

        if ($k === 1) {
            return $sign . $digits . $exponentText;
        }

        return $sign . $digits[0] . '.' . substr($digits, 1) . $exponentText;
    }

    /**
     * Returns the shortest run of significant digits that reads back as the
     * same float, and the decimal exponent of its first digit
     *
     * Precisions from 1 to 17 significant digits are tried in order with
     * `sprintf()`, which rounds correctly at each precision and does not
     * depend on the `serialize_precision` setting. Seventeen digits always
     * round-trip a double, so the loop always ends.
     *
     * @return array{string, int}
     */
    private static function shortestDigits(float $magnitude): array
    {
        $formatted = '';

        for ($precision = 0; $precision <= 16; $precision++) {
            $formatted = sprintf('%.' . $precision . 'e', $magnitude);

            if ((float) $formatted === $magnitude) {
                break;
            }
        }

        // The significand has no trailing zeros: a trailing zero would mean one
        // fewer digit also round-trips, and the loop would have stopped there.
        [$significand, $exponent] = explode('e', $formatted);

        return [str_replace('.', '', $significand), (int) $exponent];
    }
}
