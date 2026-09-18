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

namespace SocialWeb\JsonLd\Context;

/**
 * A context document as a loader returns it
 */
final readonly class LoadedDocument
{
    /**
     * @param string $documentUrl The URL the document counts as loaded from,
     *     which is the base for relative references inside it
     * @param array<mixed> | object $document The decoded document: a
     *     `stdClass` tree as `json_decode()` produces by default, or an
     *     associative array, in which a list is a JSON array and any other
     *     array is a JSON object
     */
    public function __construct(public string $documentUrl, public array | object $document)
    {
    }
}
