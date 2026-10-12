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

use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\Rdf\BlankNode;
use SocialWeb\Rdf\GraphName;
use SocialWeb\Rdf\Iri;
use SocialWeb\Rdf\Quad;
use SocialWeb\Rdf\Resource;

use function array_push;
use function substr;

/**
 * Turns the items of a list object into an RDF collection
 *
 * This is the List to RDF Conversion algorithm. Each item gets a fresh blank
 * node that holds the item under `rdf:first` and the next blank node, or
 * `rdf:nil` after the last item, under `rdf:rest`. An empty list is
 * `rdf:nil`.
 *
 * @internal
 *
 * @link https://www.w3.org/TR/json-ld11-api/#list-to-rdf-conversion JSON-LD 1.1 API, List to RDF Conversion
 */
final class ListToRdf
{
    public function __construct(private readonly ObjectToRdf $objects, private readonly BlankNodeIssuer $issuer)
    {
    }

    /**
     * Returns the head of the collection: the blank node of the first item, or
     * `rdf:nil` for an empty list
     *
     * @param list<mixed> $list The items of the list object
     * @param list<Quad> $triples The triples of the collection, in the
     *     algorithm's "list triples", for the caller to add to the dataset
     *
     * @throws DataLoss in strict mode, where the algorithm would ignore an
     *     item or a triple of an item
     * @throws InvalidArgument if an item is not a value object, list object,
     *     or node object of expanded form
     */
    public function convert(array $list, GraphName $graph, array &$triples): Resource
    {
        // Step 1.
        if ($list === []) {
            return new Iri(Vocabulary::RDF_NIL);
        }

        // Step 2.
        $blankNodes = [];

        foreach ($list as $index => $item) {
            $blankNodes[$index] = new BlankNode(substr($this->issuer->issue(), 2));
        }

        $first = new Iri(Vocabulary::RDF_FIRST);
        $rest = new Iri(Vocabulary::RDF_REST);
        $nil = new Iri(Vocabulary::RDF_NIL);

        // Step 3.
        foreach ($list as $index => $item) {
            $subject = $blankNodes[$index];

            // Steps 3.1 and 3.2.
            $embedded = [];
            $object = $this->objects->convert($item, $graph, $embedded);

            // Step 3.3.
            if ($object !== null) {
                $triples[] = new Quad($subject, $first, $object, $graph);
            }

            // Step 3.4.
            $triples[] = new Quad($subject, $rest, $blankNodes[$index + 1] ?? $nil, $graph);

            // Step 3.5.
            array_push($triples, ...$embedded);
        }

        // Step 4.
        return $blankNodes[0];
    }
}
