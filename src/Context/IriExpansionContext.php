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
 * The parts of an active context that IRI expansion reads
 *
 * What IRI expansion reads from an active context: the base IRI, the vocabulary
 * mapping, and the definition of a term.
 *
 * An implementation need not be immutable. A term may be defined between one
 * call and the next, and termDefinition() then returns it. IRI expansion relies
 * on this during context processing, where a term is defined just before the
 * value that depends on it is expanded.
 *
 * @internal
 */
interface IriExpansionContext
{
    /**
     * The current base IRI
     */
    public ?string $baseIri { get; }

    /**
     * The vocabulary mapping
     */
    public ?string $vocabularyMapping { get; }

    public function termDefinition(string $term): ?TermDefinition;
}
