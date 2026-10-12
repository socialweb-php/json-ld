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

/**
 * A node of a node map: everything an expanded document says about one
 * subject in one graph
 *
 * @internal
 */
final class Node
{
    /**
     * @var list<mixed> The values of the node's `@type` entries: IRIs and
     *     relabeled blank node identifiers, or null where lenient expansion
     *     left one
     */
    public private(set) array $types = [];

    /**
     * The value of the node's `@index` entry, when the document gives it one
     */
    public private(set) ?string $index = null;

    /**
     * @var array<string, list<mixed>> The values of each property: value
     *     objects, node references, and list objects, in the order the
     *     document gave them. The value objects are the document's own
     *     `stdClass` instances, and one node reference may be shared by every
     *     node under a reverse property, so a reader must not change them.
     *     PHP stores a property name that is only digits as an integer key,
     *     so cast a key with `(string)` before using it as a string.
     */
    public private(set) array $properties = [];

    /**
     * @var array<string, array<string, true>> The canonical JSON of each item
     *     the node holds, by property, with the types under `@type`, for the
     *     steps that add an item only when the node does not hold an
     *     equivalent one
     */
    private array $seen = [];

    public function __construct(public readonly string $id)
    {
    }

    /**
     * Adds a type unless the node already has an equivalent one
     */
    public function addType(mixed $type): void
    {
        if ($this->isNew('@type', $type)) {
            $this->types[] = $type;
        }
    }

    public function setIndex(string $index): void
    {
        $this->index = $index;
    }

    /**
     * Makes an empty list of values for the property if the node has none
     */
    public function addProperty(string $property): void
    {
        $this->properties[$property] ??= [];
    }

    /**
     * Adds an item to the end of the values of a property
     */
    public function append(string $property, mixed $item): void
    {
        $this->properties[$property][] = $item;
    }

    /**
     * Adds an item to the end of the values of a property unless the node
     * already holds an equivalent item there
     *
     * Two items are equivalent when they have equivalent map entries, which is
     * when their canonical JSON is the same.
     */
    public function appendOnce(string $property, mixed $item): void
    {
        if ($this->isNew($property, $item)) {
            $this->properties[$property][] = $item;
        }
    }

    /**
     * Records the item under the key and returns true if no equivalent item
     * was recorded there before
     *
     * The canonical JSON of each item is kept, so the check costs the same
     * however many items the node holds.
     */
    private function isNew(string $key, mixed $item): bool
    {
        $json = JsonCanonicalizer::canonicalize($item);

        if ($this->seen[$key][$json] ?? false) {
            return false;
        }

        $this->seen[$key][$json] = true;

        return true;
    }
}
