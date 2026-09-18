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

use function sprintf;

/**
 * Thrown in strict mode where the algorithms would otherwise silently drop
 * data
 */
final class DataLoss extends RuntimeException implements JsonLdException
{
    /**
     * @param DataLossCondition $condition Which drop point was reached
     * @param string $detail The term, value, or IRI that would have been dropped
     */
    public function __construct(
        public readonly DataLossCondition $condition,
        public readonly string $detail,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Data loss detected (%s): %s', $condition->value, $detail), 0, $previous);
    }
}
