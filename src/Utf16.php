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

use SocialWeb\JsonLd\Exception\InvalidArgument;

use function ord;
use function pack;
use function preg_match;
use function strlen;
use function substr;

/**
 * Encodes UTF-8 text as UTF-16 code units
 *
 * RFC 8785 sorts object keys by UTF-16 code units. Comparing the big-endian
 * encodings of two strings byte by byte gives that order.
 *
 * @internal
 */
final class Utf16
{
    /**
     * Returns the UTF-16 big-endian encoding of the string
     *
     * The string is validated as UTF-8 first, so the byte walk that follows
     * can read each character's length from its first byte.
     *
     * @throws InvalidArgument if the string is not valid UTF-8
     */
    public static function encode(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgument('A string must be valid UTF-8 to be encoded as UTF-16');
        }

        $encoded = '';
        $length = strlen($value);
        $offset = 0;

        while ($offset < $length) {
            $first = ord($value[$offset]);
            $size = ($first & 0x80) === 0 ? 1 : (($first & 0x20) === 0 ? 2 : (($first & 0x10) === 0 ? 3 : 4));
            $codePoint = self::codePoint(substr($value, $offset, $size));
            $offset += $size;

            if ($codePoint >= 0x10000) {
                $codePoint -= 0x10000;
                $encoded .= pack('nn', 0xD800 | ($codePoint >> 10), 0xDC00 | ($codePoint & 0x3FF));
            } else {
                $encoded .= pack('n', $codePoint);
            }
        }

        return $encoded;
    }

    /**
     * Decodes one UTF-8 encoded character, of one to four bytes, to its code
     * point
     */
    private static function codePoint(string $character): int
    {
        $first = ord($character[0]);

        return match (strlen($character)) {
            1 => $first,
            2 => (($first & 0x1F) << 6) | (ord($character[1]) & 0x3F),
            3 => (($first & 0x0F) << 12) | ((ord($character[1]) & 0x3F) << 6) | (ord($character[2]) & 0x3F),
            default => (($first & 0x07) << 18)
                | ((ord($character[1]) & 0x3F) << 12)
                | ((ord($character[2]) & 0x3F) << 6)
                | (ord($character[3]) & 0x3F),
        };
    }
}
