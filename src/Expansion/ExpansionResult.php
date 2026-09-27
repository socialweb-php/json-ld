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

namespace SocialWeb\JsonLd\Expansion;

use stdClass;

use function array_key_exists;
use function is_array;
use function ksort;

use const SORT_STRING;

/**
 * The result map that steps 12 through 14 of the Expansion Algorithm fill in
 *
 * Keyword entries, properties, and the reverse map are kept apart until the
 * end, so that each keeps its own type.
 *
 * @internal
 */
final class ExpansionResult
{
    /**
     * @var array<string, mixed>
     */
    private array $keywords = [];

    /**
     * @var array<string, list<mixed>>
     */
    private array $properties = [];

    /**
     * @var array<string, list<mixed>>
     */
    private array $reverse = [];

    /**
     * Returns true if the result has an entry for the keyword
     */
    public function has(string $keyword): bool
    {
        return array_key_exists($keyword, $this->keywords) || ($keyword === '@reverse' && $this->reverse !== []);
    }

    /**
     * Returns the value of a keyword entry other than `@reverse`, or `null`
     */
    public function get(string $keyword): mixed
    {
        return $this->keywords[$keyword] ?? null;
    }

    public function set(string $keyword, mixed $value): void
    {
        $this->keywords[$keyword] = $value;
    }

    /**
     * Adds items to the end of a keyword entry that the result already has
     *
     * If the value of the entry is not a list, it becomes a list of one, and
     * the items follow it.
     *
     * @param list<mixed> $items
     */
    public function append(string $keyword, array $items): void
    {
        if (!is_array($this->keywords[$keyword])) {
            $this->keywords[$keyword] = [$this->keywords[$keyword]];
        }

        foreach ($items as $item) {
            $this->keywords[$keyword][] = $item;
        }
    }

    /**
     * The "add value" utility of the specification, with its `as array` flag
     * set to true
     *
     * That is, the entry is always a list, and when the value is a list, its
     * items are added one by one.
     *
     * @param stdClass | list<mixed> $value
     */
    public function add(string $property, stdClass | array $value): void
    {
        $this->properties[$property] ??= [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            $this->properties[$property][] = $item;
        }
    }

    /**
     * Adds one item to a property of the reverse map
     */
    public function addReverse(string $property, mixed $item): void
    {
        $this->reverse[$property][] = $item;
    }

    /**
     * Returns every entry in code point order of the keys, with the reverse
     * map under `@reverse` if it holds anything
     *
     * The order makes the output the same whatever form the input used for its
     * keys (a term, a compact IRI, a full IRI, or a keyword alias) and whatever
     * order it put them in.
     *
     * @return array<string, mixed>
     */
    public function entries(): array
    {
        $entries = [...$this->keywords, ...$this->properties];

        if ($this->reverse !== []) {
            $reverse = $this->reverse;
            ksort($reverse, SORT_STRING);
            $entries['@reverse'] = (object) $reverse;
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }
}
