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

use function array_diff;
use function array_keys;
use function get_object_vars;
use function property_exists;

/**
 * Distinguishes the kinds of map in expanded JSON-LD, as JSON-LD 1.1 defines
 * them
 *
 * @internal
 */
final class ObjectForms
{
    /**
     * A value object has an `@value` entry
     */
    public static function isValueObject(mixed $value): bool
    {
        return $value instanceof stdClass && property_exists($value, '@value');
    }

    /**
     * A list object has an `@list` entry
     */
    public static function isListObject(mixed $value): bool
    {
        return $value instanceof stdClass && property_exists($value, '@list');
    }

    /**
     * A graph object has an `@graph` entry and may have `@id` and `@index`
     * entries, and nothing else
     */
    public static function isGraphObject(mixed $value): bool
    {
        return $value instanceof stdClass
            && property_exists($value, '@graph')
            && array_diff(array_keys(get_object_vars($value)), ['@graph', '@id', '@index']) === [];
    }

    /**
     * A node object is a map with none of `@value`, `@list`, and `@set`
     */
    public static function isNodeObject(mixed $value): bool
    {
        return $value instanceof stdClass
            && !property_exists($value, '@value')
            && !property_exists($value, '@list')
            && !property_exists($value, '@set');
    }
}
