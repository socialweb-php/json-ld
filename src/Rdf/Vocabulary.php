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

namespace SocialWeb\JsonLd\Rdf;

/**
 * IRIs that conversion to RDF needs
 *
 * socialweb/rdf's vocabulary classes hold only `rdf:langString` and
 * `xsd:string`, which this library uses from there. The rest live here until
 * moved to socialweb/rdf.
 *
 * @internal
 */
final class Vocabulary
{
    public const string RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    public const string RDF_TYPE = self::RDF . 'type';

    public const string RDF_FIRST = self::RDF . 'first';

    public const string RDF_REST = self::RDF . 'rest';

    public const string RDF_NIL = self::RDF . 'nil';

    public const string RDF_JSON = self::RDF . 'JSON';

    public const string RDF_VALUE = self::RDF . 'value';

    public const string RDF_LANGUAGE = self::RDF . 'language';

    public const string RDF_DIRECTION = self::RDF . 'direction';

    public const string XSD = 'http://www.w3.org/2001/XMLSchema#';

    public const string XSD_INTEGER = self::XSD . 'integer';

    public const string XSD_DOUBLE = self::XSD . 'double';

    public const string XSD_BOOLEAN = self::XSD . 'boolean';

    /**
     * The namespace of the datatypes that encode language and base direction
     * when the `rdfDirection` option is `i18n-datatype`
     */
    public const string I18N = 'https://www.w3.org/ns/i18n#';
}
