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

use JsonSerializable;
use stdClass;

use function array_map;
use function get_object_vars;
use function is_array;
use function json_encode;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * A JSON-LD document in expanded form, as `Processor::expand()` returns it
 *
 * @link https://www.w3.org/TR/json-ld11/#expanded-document-form JSON-LD 1.1, Expanded Document Form
 */
final readonly class ExpandedDocument implements JsonSerializable
{
    /**
     * The largest depth `json_encode()` accepts, 2^31 - 1
     */
    private const int ENCODER_MAX_DEPTH = 2_147_483_647;

    /**
     * @internal Call `Processor::expand()` to get an expanded document. This
     *     constructor is not part of the public contract and may change without
     *     notice.
     *
     * @param list<mixed> $nodes The expanded node objects, with JSON objects
     *     as `stdClass`
     */
    public function __construct(private array $nodes)
    {
    }

    /**
     * Returns the expanded document as a list of node objects, with JSON
     * objects as `stdClass`, so that an empty object stays an object
     *
     * The list is a copy. Changing it does not change this document.
     *
     * @return list<mixed>
     */
    public function jsonSerialize(): array
    {
        return array_map(self::copy(...), $this->nodes);
    }

    /**
     * Returns the expanded document as JSON text, without pretty printing
     */
    public function toJson(): string
    {
        // The nodes are encoded directly, without the copy that jsonSerialize()
        // makes, because encoding only reads them.
        //
        // The depth is set to the largest that json_encode() accepts. Its
        // default of 512 is too small. Expansion adds levels of its own, and a
        // graph container adds several for each level of the input, so a
        // document within Limits::maxDepth can be deeper than 512, once it is
        // expanded.
        return json_encode(
            $this->nodes,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            self::ENCODER_MAX_DEPTH,
        );
    }

    /**
     * Copies a value and everything inside it
     *
     * The recursion is as deep as the document. A Processor has already
     * bounded that depth with Limits::maxDepth, so there is no guard here.
     */
    private static function copy(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::copy(...), $value);
        }

        if (!$value instanceof stdClass) {
            return $value;
        }

        $copy = new stdClass();

        foreach (get_object_vars($value) as $key => $entry) {
            $copy->{$key} = self::copy($entry);
        }

        return $copy;
    }
}
