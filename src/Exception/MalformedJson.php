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
use Throwable;

use function sprintf;

/**
 * Thrown when a document is not valid JSON, or contains a value that JSON
 * cannot represent, such as a non-finite number or a string that is not
 * valid UTF-8
 */
final class MalformedJson extends RuntimeException implements JsonLdException
{
    /**
     * @param int $jsonError The decoder's error code, one of PHP's JSON_ERROR_*
     *     constants, or 0 when the problem was found after decoding
     * @param string $reason A short description of what was wrong
     */
    public function __construct(public readonly int $jsonError, string $reason, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Malformed JSON: %s', $reason), 0, $previous);
    }
}
