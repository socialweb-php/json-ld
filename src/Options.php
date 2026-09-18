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

use SocialWeb\JsonLd\Exception\InvalidArgument;

use function sprintf;

/**
 * Options for a Processor
 *
 * These are the JSON-LD 1.1 API options that apply to expansion and to
 * conversion to RDF, plus this library's own strict flag, limits, and
 * restrictions. Options that apply only to compaction, framing, conversion
 * from RDF, or HTML are not offered.
 */
final readonly class Options
{
    /**
     * @param string | null $base The base IRI for resolving relative
     *     references; `null`, the default, leaves them unresolved
     * @param string | array<mixed> | object | null $expandContext A context
     *     applied before the document's own; a string is a context URL
     * @param ProcessingMode $processingMode `JsonLd10` mode turns JSON-LD 1.1
     *     features into processing errors
     * @param RdfDirection | null $rdfDirection How base direction reaches RDF;
     *     `null`, the default, ignores it
     * @param bool $strict If `true`, data the algorithms would drop is treated
     *     as an error
     * @param Limits $limits Bounds on document size
     * @param Restrictions $restrictions Features of the expanded document to
     *     refuse
     *
     * @throws InvalidArgument if the base is given and is not a well-formed
     *     absolute IRI
     */
    public function __construct(
        public ?string $base = null,
        public string | array | object | null $expandContext = null,
        public ProcessingMode $processingMode = ProcessingMode::JsonLd11,
        public ?RdfDirection $rdfDirection = null,
        public bool $strict = true,
        public Limits $limits = new Limits(),
        public Restrictions $restrictions = new Restrictions(),
    ) {
        if ($base !== null && !Grammar::isWellFormedIri($base)) {
            throw new InvalidArgument(sprintf('The base must be a well-formed absolute IRI; "%s" given', $base));
        }
    }
}
