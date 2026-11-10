<?php

/**
 * This file is part of socialweb/json-ld
 *
 * Copyright © socialweb/json-ld Contributors
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

namespace SocialWeb\JsonLd\Exception;

/**
 * The points where the JSON-LD algorithms silently drop data, which strict
 * mode turns into errors
 *
 * Each case names a step of the expansion or deserialization algorithm that
 * says "drop" or "continue." See the JSON-LD 1.1 Processing Algorithms and API
 * specification. The cases are this package's own, not the specification's,
 * so the enum has no backing strings; a `DataLoss` message names the case.
 */
enum DataLossReason
{
    /**
     * A key or a value that expansion can give no meaning
     *
     * This covers three cases:
     *
     * - A key that is neither an IRI nor a keyword.
     * - A keyword that the algorithm has no step for. `@container` is one in
     *   any mode. `@included` and `@direction` are two more in `json-ld-1.0`
     *   mode.
     * - A key or a value that the active context defines as null. A `@type`
     *   value and the key of a type map are examples.
     */
    case UndefinedProperty;

    /**
     * A term, a key, or a value that has the form of a keyword but is not
     * one, such as an `@id`, an `@reverse`, or a `@type` value
     */
    case ReservedTerm;

    /**
     * A value object whose `@value` is null or an empty array
     */
    case NullValue;

    /**
     * At the top level or inside `@graph`, an empty map, a map with only
     * `@id`, or a scalar, list, or value with no property
     */
    case FreeFloatingNode;

    /**
     * A map with only `@value`, `@list`, or `@language` where a node is
     * required
     */
    case FreeFloatingValue;

    /**
     * A value has `@direction` and the processor's `rdfDirection` is null
     */
    case DroppedDirection;

    /**
     * A property that is a blank node identifier
     */
    case BlankNodePredicate;

    /**
     * A subject that is not a well-formed IRI
     */
    case RelativeSubject;

    /**
     * A predicate that is not a well-formed IRI
     */
    case RelativePredicate;

    /**
     * A node object whose `@id` is not a well-formed IRI
     */
    case RelativeObject;

    /**
     * A graph name that is not a well-formed IRI
     */
    case RelativeGraph;

    /**
     * A `@type` value that is not a well-formed IRI
     */
    case RelativeType;

    /**
     * A value object whose `@type` is not a well-formed IRI
     */
    case MalformedDatatype;

    /**
     * A value object whose `@language` is not well-formed
     */
    case MalformedLanguageTag;

    /**
     * A blank node identifier that does not match the N-Quads label
     * production
     */
    case MalformedBlankNodeIdentifier;
}
