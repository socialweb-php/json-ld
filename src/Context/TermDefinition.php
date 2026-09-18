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

use SocialWeb\JsonLd\Rdf\JsonCanonicalizer;

use function in_array;

/**
 * A term definition, as JSON-LD 1.1 Processing Algorithms and API describes it
 *
 * Three of the optional parts can be present with a null value, which means
 * something different from being absent: a scoped context of null resets the
 * active context, and a language or direction mapping of null says that
 * values of the term have no language or direction whatever the defaults
 * are. Each of those has a flag that says whether it is present.
 *
 * @internal
 */
final readonly class TermDefinition
{
    /**
     * @param string | null $iriMapping The IRI, blank node identifier, or
     *     keyword the term maps to; `null` when the term is defined as null
     * @param bool $prefix Whether the term may be used as the prefix of a
     *     compact IRI
     * @param bool $protected Whether later contexts are barred from
     *     redefining the term
     * @param bool $reverse Whether the term is a reverse property
     * @param string | null $baseUrl The base URL for resolving context URLs
     *     in the scoped context
     * @param bool $hasContext Whether the term has a scoped context
     * @param mixed $context The scoped context, in the internal form, exactly
     *     as the term definition gave it
     * @param list<string> $containerMapping The container keywords, or an
     *     empty list for no container mapping
     * @param bool $hasDirectionMapping Whether the term has a direction mapping
     * @param string | null $directionMapping `ltr`, `rtl`, or `null`
     * @param string | null $indexMapping The property that an index container
     *     indexes by
     * @param bool $hasLanguageMapping Whether the term has a language mapping
     * @param string | null $languageMapping The language, exactly as given, or
     *     `null`
     * @param string | null $nestValue `@nest` or a term that aliases it
     * @param string | null $typeMapping An IRI, `@id`, `@vocab`, `@json`, or
     *     `@none`
     */
    public function __construct(
        public ?string $iriMapping = null,
        public bool $prefix = false,
        public bool $protected = false,
        public bool $reverse = false,
        public ?string $baseUrl = null,
        public bool $hasContext = false,
        public mixed $context = null,
        public array $containerMapping = [],
        public bool $hasDirectionMapping = false,
        public ?string $directionMapping = null,
        public ?string $indexMapping = null,
        public bool $hasLanguageMapping = false,
        public ?string $languageMapping = null,
        public ?string $nestValue = null,
        public ?string $typeMapping = null,
    ) {
    }

    /**
     * Returns true if the container mapping includes the keyword
     */
    public function hasContainer(string $keyword): bool
    {
        return in_array($keyword, $this->containerMapping, true);
    }

    /**
     * Returns true if the other definition is the same as this one in
     * everything but the protected flag
     *
     * This is the comparison that decides whether redefining a protected term
     * is an error. Scoped contexts are the same when they are the same JSON,
     * whatever the order of their entries.
     */
    public function equalsExceptProtected(self $other): bool
    {
        return $this->iriMapping === $other->iriMapping
            && $this->prefix === $other->prefix
            && $this->reverse === $other->reverse
            && $this->baseUrl === $other->baseUrl
            && $this->hasContext === $other->hasContext
            && JsonCanonicalizer::canonicalize($this->context) === JsonCanonicalizer::canonicalize($other->context)
            && $this->containerMapping === $other->containerMapping
            && $this->hasDirectionMapping === $other->hasDirectionMapping
            && $this->directionMapping === $other->directionMapping
            && $this->indexMapping === $other->indexMapping
            && $this->hasLanguageMapping === $other->hasLanguageMapping
            && $this->languageMapping === $other->languageMapping
            && $this->nestValue === $other->nestValue
            && $this->typeMapping === $other->typeMapping;
    }
}
