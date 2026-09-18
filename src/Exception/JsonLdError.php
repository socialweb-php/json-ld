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
use SocialWeb\JsonLd\ErrorCode;
use Throwable;

use function sprintf;

/**
 * Thrown when a document fails a rule of the JSON-LD 1.1 specification
 *
 * The specification defines one error type with a code for each rule, and so
 * does this library. See JSON-LD 1.1 Processing Algorithms and API section
 * 10.2, "JsonLdErrorCode".
 */
final class JsonLdError extends RuntimeException implements JsonLdException
{
    /**
     * @param ErrorCode $errorCode The specification's error code
     * @param string $detail The offending term, value, or IRI, when known
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $detail = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $detail === '' ? $errorCode->value : sprintf('%s: %s', $errorCode->value, $detail),
            0,
            $previous,
        );
    }
}
