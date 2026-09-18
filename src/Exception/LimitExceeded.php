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
 * Thrown when a document exceeds one of the caller's limits
 */
final class LimitExceeded extends RuntimeException implements JsonLdException
{
    /**
     * @param string $limit The name of the Limits option that was exceeded,
     *     `maxDepth` or `maxValues`
     * @param int $value The configured limit
     */
    public function __construct(public readonly string $limit, public readonly int $value, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('The document exceeds the %s limit of %d', $limit, $value), 0, $previous);
    }
}
