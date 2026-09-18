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

use Closure;
use SocialWeb\JsonLd\Grammar;
use SocialWeb\JsonLd\IriResolver;
use SocialWeb\JsonLd\Keywords;

use function str_starts_with;
use function strpos;
use function substr;

/**
 * IRI Expansion, JSON-LD 1.1 Processing Algorithms and API section 5.2
 *
 * The step numbers in the comments are the specification's.
 *
 * @internal
 */
final class IriExpander
{
    /**
     * Returns the value expanded to an IRI, a blank node identifier, or a
     * keyword; or `null` if the value is null, is defined as null, or has the
     * form of a keyword without being one
     *
     * During context processing, the specification passes this algorithm the
     * local context and the map of defined terms, so that a term the value
     * depends on is defined before it is used. Here the context processor
     * passes a closure that does exactly that and returns the active context
     * as it is afterward.
     *
     * @param bool $documentRelative Whether to resolve a relative reference
     *     against the base IRI
     * @param bool $vocab Whether the value may be a term or be relative to the
     *     vocabulary mapping
     * @param (Closure(string): ActiveContext) | null $define Given a term,
     *     defines it if the local context has it and it is not yet defined
     */
    public static function expand(
        ActiveContext $activeContext,
        ?string $value,
        bool $documentRelative = false,
        bool $vocab = false,
        ?Closure $define = null,
    ): ?string {
        // Step 1.
        if ($value === null || Keywords::isKeyword($value)) {
            return $value;
        }

        // Step 2.
        if (Keywords::hasKeywordForm($value)) {
            return null;
        }

        // Step 3.
        if ($define !== null) {
            $activeContext = $define($value);
        }

        $definition = $activeContext->termDefinition($value);

        if ($definition !== null) {
            // Step 4.
            if ($definition->iriMapping !== null && Keywords::isKeyword($definition->iriMapping)) {
                return $definition->iriMapping;
            }

            // Step 5.
            if ($vocab) {
                return $definition->iriMapping;
            }
        }

        // Step 6. The search starts after the first character.
        $colon = strpos(substr($value, 1), ':');

        if ($colon !== false) {
            // Step 6.1.
            $prefix = substr($value, 0, $colon + 1);
            $suffix = substr($value, $colon + 2);

            // Step 6.2.
            if ($prefix === '_' || str_starts_with($suffix, '//')) {
                return $value;
            }

            // Step 6.3.
            if ($define !== null) {
                $activeContext = $define($prefix);
            }

            // Step 6.4.
            $prefixDefinition = $activeContext->termDefinition($prefix);

            if ($prefixDefinition !== null && $prefixDefinition->iriMapping !== null && $prefixDefinition->prefix) {
                return $prefixDefinition->iriMapping . $suffix;
            }

            // Step 6.5.
            if (Grammar::isAbsoluteIri($value)) {
                return $value;
            }
        }

        // Step 7.
        if ($vocab && $activeContext->vocabularyMapping !== null) {
            return $activeContext->vocabularyMapping . $value;
        }

        // Step 8.
        if ($documentRelative && $activeContext->baseIri !== null) {
            return IriResolver::resolve($value, $activeContext->baseIri);
        }

        // Step 9.
        return $value;
    }
}
