<?php

/**
 * This file is part of socialweb/json-ld
 *
 * Copyright © socialweb/json-ld Contributors
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

use Throwable;
use UnexpectedValueException;

use function sprintf;
use function ucfirst;

/**
 * Thrown when a document fails a rule of the JSON-LD 1.1 specification
 *
 * The specification defines one error type with a code for each rule, and so
 * does this library. See JSON-LD 1.1 Processing Algorithms and API section
 * 9.6.2, "JsonLdErrorCode". The message is the code with its first letter
 * capitalized, followed by the detail when there is one.
 */
final class JsonLdError extends UnexpectedValueException implements JsonLdException
{
    /**
     * @param JsonLdErrorCode $reason The specification's error code
     * @param string $detail What the message says after the code: the
     *     offending term, entry, or IRI in double quotes, or a few words
     *     about what was wrong
     */
    public function __construct(
        public readonly JsonLdErrorCode $reason,
        string $detail = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $detail === '' ? ucfirst($reason->value) : sprintf('%s: %s', ucfirst($reason->value), $detail),
            0,
            $previous,
        );
    }
}
