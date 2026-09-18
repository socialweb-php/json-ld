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

use function array_any;

/**
 * An active context, as JSON-LD 1.1 Processing Algorithms and API section 4.1
 * describes it
 *
 * The value is immutable. Context processing returns a new active context
 * and leaves the one it was given alone. The inverse context is absent
 * because only compaction uses it.
 *
 * @internal
 */
final readonly class ActiveContext
{
    /**
     * @param array<TermDefinition> $termDefinitions The term definitions by
     *     term; PHP turns a term made of digits into an integer key, so cast
     *     keys to string when reading them
     * @param string | null $baseIri The current base IRI
     * @param string | null $originalBaseUrl The base that a context of null
     *     returns to
     * @param string | null $vocabularyMapping The vocabulary mapping
     * @param string | null $defaultLanguage The default language, exactly as
     *     the context gave it
     * @param string | null $defaultBaseDirection `ltr`, `rtl`, or null
     * @param self | null $previousContext The context to return to when a
     *     non-propagated context goes out of scope
     */
    public function __construct(
        public array $termDefinitions = [],
        public ?string $baseIri = null,
        public ?string $originalBaseUrl = null,
        public ?string $vocabularyMapping = null,
        public ?string $defaultLanguage = null,
        public ?string $defaultBaseDirection = null,
        public ?self $previousContext = null,
    ) {
    }

    /**
     * Returns a newly initialized active context whose base IRI and original
     * base URL are the given base
     */
    public static function initial(?string $base): self
    {
        return new self(baseIri: $base, originalBaseUrl: $base);
    }

    public function termDefinition(string $term): ?TermDefinition
    {
        return $this->termDefinitions[$term] ?? null;
    }

    /**
     * Returns true if any term definition is protected
     */
    public function hasProtectedTermDefinitions(): bool
    {
        return array_any($this->termDefinitions, fn ($definition) => $definition->protected);
    }

    public function withTermDefinition(string $term, TermDefinition $definition): self
    {
        $termDefinitions = $this->termDefinitions;
        $termDefinitions[$term] = $definition;

        return clone($this, ['termDefinitions' => $termDefinitions]);
    }

    public function withoutTermDefinition(string $term): self
    {
        $termDefinitions = $this->termDefinitions;
        unset($termDefinitions[$term]);

        return clone($this, ['termDefinitions' => $termDefinitions]);
    }

    public function withBaseIri(?string $baseIri): self
    {
        return clone($this, ['baseIri' => $baseIri]);
    }

    public function withVocabularyMapping(?string $vocabularyMapping): self
    {
        return clone($this, ['vocabularyMapping' => $vocabularyMapping]);
    }

    public function withDefaultLanguage(?string $defaultLanguage): self
    {
        return clone($this, ['defaultLanguage' => $defaultLanguage]);
    }

    public function withDefaultBaseDirection(?string $defaultBaseDirection): self
    {
        return clone($this, ['defaultBaseDirection' => $defaultBaseDirection]);
    }

    public function withPreviousContext(?self $previousContext): self
    {
        return clone($this, ['previousContext' => $previousContext]);
    }
}
