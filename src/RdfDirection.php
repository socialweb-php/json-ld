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
 * The `rdfDirection` option of JSON-LD 1.1 Processing Algorithms and API; it
 * indicates how a value's base direction is represented in RDF
 *
 * When the option is not set, base direction is not represented in RDF.
 */
enum RdfDirection: string
{
    /**
     * A literal with a datatype IRI in the `https://www.w3.org/ns/i18n#`
     * namespace that encodes the language and direction
     */
    case I18nDatatype = 'i18n-datatype';

    /**
     * A blank node with `rdf:value`, `rdf:language`, and `rdf:direction`
     * properties
     */
    case CompoundLiteral = 'compound-literal';
}
