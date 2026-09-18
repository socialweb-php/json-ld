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

/**
 * Features of an expanded document that a caller may refuse
 *
 * An RDF dataset does not record the JSON tree it came from, and JSON-LD
 * offers several ways to write the same dataset. A caller that binds
 * something to the dataset, such as a hash, but then interprets the tree,
 * needs the tree to be the only one that produces that dataset. Each
 * restriction refuses one feature that breaks that property. The check runs
 * on the expanded document, where aliases have already been resolved.
 */
final readonly class Restrictions
{
    /**
     * @param bool $forbidNamedGraphs Reject any `@graph` entry in an expanded
     *     node object; equivalent to requiring every quad to be in the
     *     default graph
     * @param bool $forbidIncludedBlocks Reject any `@included` entry
     * @param bool $forbidReverseProperties Reject any `@reverse` entry
     * @param bool $requireSingleTopLevelNode Reject an expanded document whose
     *     top level is not exactly one node object
     */
    public function __construct(
        public bool $forbidNamedGraphs = false,
        public bool $forbidIncludedBlocks = false,
        public bool $forbidReverseProperties = false,
        public bool $requireSingleTopLevelNode = false,
    ) {
    }

    /**
     * Returns restrictions with every option turned on
     */
    public static function all(): self
    {
        return new self(true, true, true, true);
    }
}
