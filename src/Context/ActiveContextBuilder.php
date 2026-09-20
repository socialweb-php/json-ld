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

/**
 * An active context while the terms of one context definition are defined
 *
 * Create Term Definition sets and removes one term at a time. An immutable
 * active context would copy every term definition it holds for each of those
 * changes, which takes quadratic time in the number of terms. The builder
 * holds the term definitions alone, so each change is made in place, and
 * `build()` hands them to a new active context without copying them.
 *
 * The active context the builder was made from is never changed. Neither is
 * one it has built: PHP copies an array that has two holders before writing
 * to it.
 *
 * @internal
 */
final class ActiveContextBuilder implements IriExpansionContext
{
    public readonly ?string $baseIri;

    public readonly ?string $vocabularyMapping;

    /**
     * @var array<TermDefinition>
     */
    private array $termDefinitions;

    public function __construct(private readonly ActiveContext $activeContext)
    {
        $this->baseIri = $activeContext->baseIri;
        $this->vocabularyMapping = $activeContext->vocabularyMapping;
        $this->termDefinitions = $activeContext->termDefinitions;
    }

    public function termDefinition(string $term): ?TermDefinition
    {
        return $this->termDefinitions[$term] ?? null;
    }

    public function set(string $term, TermDefinition $definition): void
    {
        $this->termDefinitions[$term] = $definition;
    }

    public function remove(string $term): void
    {
        unset($this->termDefinitions[$term]);
    }

    /**
     * Returns the active context the builder was made from, with the term
     * definitions as they are now
     */
    public function build(): ActiveContext
    {
        return $this->activeContext->withTermDefinitions($this->termDefinitions);
    }
}
