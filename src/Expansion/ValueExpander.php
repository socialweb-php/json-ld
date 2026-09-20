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

use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\IriExpander;
use stdClass;

use function is_string;
use function ksort;
use function strtolower;

use const SORT_STRING;

/**
 * Value Expansion, JSON-LD 1.1 Processing Algorithms and API section 5.3
 *
 * The step numbers in the comments are the specification's.
 *
 * @internal
 */
final class ValueExpander
{
    /**
     * Returns the value, a scalar, as a value object; or as a node reference
     * when the active property's type mapping says its values are IRIs
     *
     * A language tag is lowercased, which the specification allows.
     */
    public static function expand(
        ActiveContext $activeContext,
        string $activeProperty,
        mixed $value,
    ): stdClass {
        $definition = $activeContext->termDefinition($activeProperty);
        $typeMapping = $definition?->typeMapping;

        // Step 1.
        if ($typeMapping === '@id' && is_string($value)) {
            return (object) ['@id' => IriExpander::expand($activeContext, $value, documentRelative: true)];
        }

        // Step 2.
        if ($typeMapping === '@vocab' && is_string($value)) {
            return (object) ['@id' => IriExpander::expand($activeContext, $value, documentRelative: true, vocab: true)];
        }

        // Step 3.
        $result = ['@value' => $value];

        if ($typeMapping !== null && $typeMapping !== '@id' && $typeMapping !== '@vocab' && $typeMapping !== '@none') {
            // Step 4.
            $result['@type'] = $typeMapping;
        } elseif (is_string($value)) {
            // Step 5.
            $language = $definition !== null && $definition->hasLanguageMapping
                ? $definition->languageMapping
                : $activeContext->defaultLanguage;
            $direction = $definition !== null && $definition->hasDirectionMapping
                ? $definition->directionMapping
                : $activeContext->defaultBaseDirection;

            if ($language !== null) {
                $result['@language'] = strtolower($language);
            }

            if ($direction !== null) {
                $result['@direction'] = $direction;
            }
        }

        // Step 6. Entries are in code point order throughout the output.
        ksort($result, SORT_STRING);

        return (object) $result;
    }
}
