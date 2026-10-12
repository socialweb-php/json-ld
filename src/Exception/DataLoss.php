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

namespace SocialWeb\JsonLd\Exception;

use RuntimeException;
use SocialWeb\JsonLd\DataLossCondition;
use Throwable;

use function preg_match;
use function preg_match_all;
use function sprintf;
use function strlen;
use function substr;

/**
 * Thrown in strict mode where the algorithms would otherwise silently drop
 * data
 *
 * The detail is everything that would have been dropped, however large. The
 * message, which is what a log usually receives, carries the condition and
 * the first characters of the detail, and says how many there are when it
 * does not carry them all.
 */
final class DataLoss extends RuntimeException implements JsonLdException
{
    /**
     * The greatest number of characters of the detail that the message
     * carries
     */
    public const int MESSAGE_DETAIL_LENGTH = 256;

    /**
     * @param DataLossCondition $condition Which drop point was reached
     * @param string $detail The term, value, or IRI that would have been
     *     dropped, whole: a string as it was, or a map or an array as its
     *     canonical JSON
     */
    public function __construct(
        public readonly DataLossCondition $condition,
        public readonly string $detail,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Data loss detected (%s): %s', $condition->value, self::summary($detail)),
            0,
            $previous,
        );
    }

    /**
     * Returns the detail when it is short, and otherwise its first characters
     * and a count of all of them
     *
     * Characters are counted as code points when the detail is valid UTF-8,
     * so the cut never splits a character; a detail that is not valid UTF-8
     * is cut by bytes.
     */
    private static function summary(string $detail): string
    {
        $limit = self::MESSAGE_DETAIL_LENGTH;

        if (preg_match('/\A.{0,' . $limit . '}\z/su', $detail) === 1) {
            return $detail;
        }

        if (preg_match('/\A.{' . $limit . '}/su', $detail, $matches) === 1) {
            return sprintf('%s (first %d of %d characters)', $matches[0], $limit, preg_match_all('/./su', $detail));
        }

        // The detail is not valid UTF-8, so it is cut by bytes.
        return strlen($detail) <= $limit
            ? $detail
            : sprintf('%s (first %d of %d bytes)', substr($detail, 0, $limit), $limit, strlen($detail));
    }
}
