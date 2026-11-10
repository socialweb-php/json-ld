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

namespace SocialWeb\JsonLd\Rdf;

use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\DataLossReason;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Grammar;
use SocialWeb\JsonLd\Keywords;
use SocialWeb\JsonLd\Settings;
use SocialWeb\Rdf\Dataset;
use SocialWeb\Rdf\DefaultGraph;
use SocialWeb\Rdf\GraphName;
use SocialWeb\Rdf\Iri;
use SocialWeb\Rdf\Quad;
use SocialWeb\Rdf\Resource;

use function is_string;
use function ksort;
use function str_starts_with;

use const SORT_STRING;

/**
 * Builds the RDF dataset of a node map
 *
 * This is the Deserialize JSON-LD to RDF algorithm. Every graph of the node
 * map, every node of a graph, and every property of a node is visited in code
 * point order, so the dataset holds its quads in one order for a document
 * whatever the order of the document's entries. A term that is not
 * well-formed is a strict mode decision point: in strict mode the conversion
 * stops with `DataLoss`, and in lenient mode the algorithm skips what the
 * term is part of, as it is written. The reader of the node map treats its
 * values as read only, because they are the document's own objects.
 *
 * @internal
 *
 * @link https://www.w3.org/TR/json-ld11-api/#deserialize-json-ld-to-rdf-algorithm JSON-LD 1.1 API, Deserialize JSON-LD to RDF
 */
final class RdfConverter
{
    private readonly bool $strict;
    private readonly ObjectToRdf $objects;
    private readonly Dataset $dataset;
    private readonly Iri $type;

    private function __construct(BlankNodeIssuer $issuer, Settings $settings)
    {
        $this->strict = $settings->strict;
        $this->objects = new ObjectToRdf($issuer, $settings->strict, $settings->rdfDirection);
        $this->dataset = new Dataset();
        $this->type = new Iri(Vocabulary::RDF_TYPE);
    }

    /**
     * Returns the dataset of a node map
     *
     * @param BlankNodeIssuer $issuer The issuer that relabeled the blank nodes
     *     of the node map, so that the blank nodes made here for lists and
     *     compound literals never collide with them
     *
     * @throws DataLoss in strict mode, if a term is not well-formed, a property
     *     is a blank node identifier or a keyword, or a value carries a
     *     direction that the `rdfDirection` option gives no form in RDF
     * @throws InvalidArgument if the node map holds something that is not of
     *     expanded form, which only a document built by hand can bring
     */
    public static function convert(NodeMap $nodeMap, BlankNodeIssuer $issuer, Settings $settings): Dataset
    {
        $converter = new self($issuer, $settings);

        // Step 1.
        foreach ($nodeMap->graphNames() as $graphName) {
            $converter->graph($nodeMap, $graphName);
        }

        return $converter->dataset;
    }

    private function graph(NodeMap $nodeMap, string $graphName): void
    {
        // Steps 1.1 and 1.2.
        if ($graphName === NodeMap::DEFAULT_GRAPH) {
            $graph = new DefaultGraph();
        } else {
            $graph = $this->objects->resource($graphName, DataLossReason::RelativeGraph);

            if ($graph === null) {
                return;
            }
        }

        // Step 1.3.
        foreach ($nodeMap->nodes($graphName) as $node) {
            // Step 1.3.1.
            $subject = $this->objects->resource($node->id, DataLossReason::RelativeSubject);

            if ($subject === null) {
                continue;
            }

            // Step 1.3.2.1. The types are kept apart from the properties, and
            // `@type` sorts before every IRI and blank node identifier, so
            // they come first. A type is null where lenient expansion left one.
            foreach ($node->types as $type) {
                if (!is_string($type)) {
                    $this->drop(DataLossReason::RelativeType, JsonCanonicalizer::canonicalize($type));

                    continue;
                }

                $object = $this->objects->resource($type, DataLossReason::RelativeType);

                if ($object !== null) {
                    $this->dataset->add(new Quad($subject, $this->type, $object, $graph));
                }
            }

            $this->properties($node, $subject, $graph);
        }
    }

    /**
     * Steps 1.3.2.2 through 1.3.2.5: the properties of a node, in code point
     * order
     */
    private function properties(Node $node, Resource $subject, GraphName $graph): void
    {
        $properties = $node->properties;
        ksort($properties, SORT_STRING);

        foreach ($properties as $property => $values) {
            $property = (string) $property;

            // Step 1.3.2.2. Expansion leaves `@set` and `@list` beside a
            // `@type` written as a string, and node map generation makes the
            // first a property; the algorithm has no step for it.
            if (Keywords::isKeyword($property)) {
                $this->drop(DataLossReason::UndefinedProperty, $property);

                continue;
            }

            // Step 1.3.2.3. Generalized RDF is not produced, because a quad
            // cannot hold a blank node predicate.
            if (str_starts_with($property, '_:')) {
                $this->drop(DataLossReason::BlankNodePredicate, $property);

                continue;
            }

            // Step 1.3.2.4.
            if (!Grammar::isWellFormedIri($property)) {
                $this->drop(DataLossReason::RelativePredicate, $property);

                continue;
            }

            $predicate = new Iri($property);

            // Step 1.3.2.5.
            foreach ($values as $item) {
                $listTriples = [];
                $object = $this->objects->convert($item, $graph, $listTriples);

                if ($object !== null) {
                    $this->dataset->add(new Quad($subject, $predicate, $object, $graph));
                }

                foreach ($listTriples as $triple) {
                    $this->dataset->add($triple);
                }
            }
        }
    }

    /**
     * In strict mode, refuses to drop what the algorithm drops silently
     */
    private function drop(DataLossReason $reason, string $detail): void
    {
        if ($this->strict) {
            throw new DataLoss($reason, $detail);
        }
    }
}
