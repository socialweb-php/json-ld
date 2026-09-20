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

use SocialWeb\JsonLd\Exception\RestrictedFeature;
use SocialWeb\JsonLd\Restrictions;
use stdClass;

use function array_key_exists;
use function count;
use function get_object_vars;
use function is_array;
use function is_string;

/**
 * Checks an expanded document against the caller's restrictions
 *
 * Expansion has already resolved aliases, so a keyword appears as itself no
 * matter how the input wrote it.
 *
 * @internal
 */
final class RestrictionChecker
{
    /**
     * @param list<mixed> $nodes The expanded document
     *
     * @throws RestrictedFeature if the document uses a feature that the
     *     restrictions forbid
     */
    public static function check(Restrictions $restrictions, array $nodes): void
    {
        if ($restrictions->requireSingleTopLevelNode && count($nodes) !== 1) {
            throw new RestrictedFeature('requireSingleTopLevelNode', (string) count($nodes));
        }

        // Maps each forbidden keyword to the name of the restriction that
        // forbids it. Only the restrictions that are turned on are added, and a
        // node object is checked for the keywords in the order they are added
        // here.
        $forbidden = [];

        if ($restrictions->forbidNamedGraphs) {
            $forbidden['@graph'] = 'forbidNamedGraphs';
        }

        if ($restrictions->forbidIncludedBlocks) {
            $forbidden['@included'] = 'forbidIncludedBlocks';
        }

        if ($restrictions->forbidReverseProperties) {
            $forbidden['@reverse'] = 'forbidReverseProperties';
        }

        if ($forbidden !== []) {
            self::walk($forbidden, $nodes);
        }
    }

    /**
     * @param non-empty-array<string, string> $forbidden The restrictions in
     *     force, by keyword
     */
    private static function walk(array $forbidden, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::walk($forbidden, $item);
            }
        } elseif ($value instanceof stdClass) {
            self::walkMap($forbidden, $value);
        }
    }

    /**
     * @param non-empty-array<string, string> $forbidden
     */
    private static function walkMap(array $forbidden, stdClass $value): void
    {
        $entries = get_object_vars($value);

        // The value of a JSON literal is data, whatever its keys look like.
        if (array_key_exists('@value', $entries)) {
            return;
        }

        foreach ($forbidden as $keyword => $restriction) {
            if (array_key_exists($keyword, $entries)) {
                $id = $entries['@id'] ?? null;

                throw new RestrictedFeature($restriction, is_string($id) ? $id : 'a node with no @id');
            }
        }

        foreach ($entries as $entry) {
            self::walk($forbidden, $entry);
        }
    }
}
