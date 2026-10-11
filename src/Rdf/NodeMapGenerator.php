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

use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdError;
use stdClass;

use function array_key_exists;
use function array_map;
use function get_object_vars;
use function is_array;
use function is_string;
use function ksort;
use function str_starts_with;

use const SORT_STRING;

/**
 * Builds the node map of an expanded document
 *
 * This is the Node Map Generation algorithm. It collects everything the
 * document says about a node in one place, under the graph the node is in,
 * and relabels every blank node through a BlankNodeIssuer, so that the labels
 * in the result are the ones the reference processors produce. Map entries
 * are visited in code point order of their keys, so the labels do not depend
 * on the order the document wrote its entries in.
 *
 * The document is not changed. A value object is put in the node map as it
 * is, and copied only when its `@type` is a blank node identifier that has to
 * be relabeled.
 *
 * @internal
 *
 * @link https://www.w3.org/TR/json-ld11-api/#node-map-generation JSON-LD 1.1 API, Node Map Generation
 */
final class NodeMapGenerator
{
    private readonly NodeMap $nodeMap;

    private function __construct(private readonly BlankNodeIssuer $issuer)
    {
        $this->nodeMap = new NodeMap();
    }

    /**
     * Returns the node map of an expanded document, with its blank nodes
     * relabeled by a new issuer
     *
     * @param list<mixed> $nodes The expanded document, as
     *     `ExpandedDocument::jsonSerialize()` returns it: a list of expanded
     *     node objects, with JSON objects as `stdClass`
     *
     * @throws JsonLdError with the code `conflicting indexes` if two parts of
     *     the document give one node different `@index` values
     * @throws InvalidArgument if the document is not in expanded form:
     *     something in it is neither a map nor an array, or a value object or
     *     list object is not the value of a property of a node object
     */
    public static function generate(array $nodes): NodeMap
    {
        $generator = new self(new BlankNodeIssuer());
        $generator->visit($nodes, NodeMap::DEFAULT_GRAPH, null, null);

        return $generator->nodeMap;
    }

    /**
     * One pass of the algorithm over an element
     *
     * @param string | stdClass | null $activeSubject The identifier of the
     *     node whose property is being filled, or a node reference to it when
     *     a reverse property is being processed
     * @param list<mixed> | null $list The items of the list being filled,
     *     when the element is an item of a list
     */
    private function visit(
        mixed $element,
        string $activeGraph,
        string | stdClass | null $activeSubject,
        ?string $activeProperty,
        ?array &$list = null,
    ): void {
        // Step 1.
        if (is_array($element)) {
            foreach ($element as $item) {
                $this->visit($item, $activeGraph, $activeSubject, $activeProperty, $list);
            }

            return;
        }

        // Step 2 reads the element as a map. Expansion leaves nothing else in
        // its result, so anything else means the document is not expanded.
        if (!$element instanceof stdClass) {
            throw new InvalidArgument('An expanded document holds only maps and arrays');
        }

        $entries = get_object_vars($element);

        // Step 3.
        $types = array_key_exists('@type', $entries) ? $this->relabel($entries['@type']) : null;

        // Step 4.
        if (array_key_exists('@value', $entries)) {
            if ($types !== ($entries['@type'] ?? null)) {
                $element = clone $element;
                $element->{'@type'} = $types;
            }

            if ($list === null) {
                [$subjectNode, $property] = $this->target($activeGraph, $activeSubject, $activeProperty);
                $subjectNode->appendOnce($property, $element);
            } else {
                $list[] = $element;
            }

            return;
        }

        // Step 5.
        if (array_key_exists('@list', $entries)) {
            $items = [];
            $this->visit($entries['@list'], $activeGraph, $activeSubject, $activeProperty, $items);
            $result = (object) ['@list' => $items];

            if ($list === null) {
                [$subjectNode, $property] = $this->target($activeGraph, $activeSubject, $activeProperty);
                $subjectNode->append($property, $result);
            } else {
                $list[] = $result;
            }

            return;
        }

        // Step 6: the element is a node object.

        // Step 6.1. An `@id` of null, which lenient expansion leaves where a
        // value had the form of a keyword, is read as the empty string: a map
        // cannot be keyed by null, and the empty string is no more an IRI than
        // null is, so conversion treats the node the same way.
        if (array_key_exists('@id', $entries)) {
            $id = is_string($entries['@id']) ? $entries['@id'] : '';

            if (self::isBlankNodeIdentifier($id)) {
                $id = $this->issuer->issue($id);
            }
        } else {
            $id = $this->issuer->issue();
        }

        // Steps 6.2 and 6.3.
        $node = $this->nodeMap->node($activeGraph, $id);

        if ($activeSubject instanceof stdClass && $activeProperty !== null) {
            // Step 6.4.
            $node->appendOnce($activeProperty, $activeSubject);
        } elseif ($activeProperty !== null) {
            // Step 6.5.
            $reference = (object) ['@id' => $id];

            if ($list === null) {
                [$subjectNode, $property] = $this->target($activeGraph, $activeSubject, $activeProperty);
                $subjectNode->appendOnce($property, $reference);
            } else {
                $list[] = $reference;
            }
        }

        // Step 6.6. The types of a node object are a list in expanded form.
        // Expansion leaves a `@type` written as a string beside an `@set` or
        // `@list` entry, and that string is the node's one type.
        if ($types !== null) {
            foreach (is_array($types) ? $types : [$types] as $type) {
                $node->addType($type);
            }
        }

        // Step 6.7.
        if (array_key_exists('@index', $entries)) {
            /** @var string $index */
            $index = $entries['@index'];

            if ($node->index !== null && $node->index !== $index) {
                throw new JsonLdError(ErrorCode::ConflictingIndexes, $id);
            }

            $node->setIndex($index);
        }

        // Step 6.8. The reverse map is visited in code point order of its
        // properties, so the labels the issuer gives the nodes under it do not
        // depend on the order the document wrote them in.
        if (array_key_exists('@reverse', $entries)) {
            $referencedNode = (object) ['@id' => $id];

            /** @var stdClass $reverseMap */
            $reverseMap = $entries['@reverse'];
            $reverse = get_object_vars($reverseMap);
            ksort($reverse, SORT_STRING);

            foreach ($reverse as $property => $values) {
                $this->visit($values, $activeGraph, $referencedNode, (string) $property);
            }
        }

        // Step 6.9.
        if (array_key_exists('@graph', $entries)) {
            $this->visit($entries['@graph'], $id, null, null);
        }

        // Step 6.10.
        if (array_key_exists('@included', $entries)) {
            $this->visit($entries['@included'], $activeGraph, null, null);
        }

        // Step 6.11.
        unset(
            $entries['@id'],
            $entries['@type'],
            $entries['@index'],
            $entries['@reverse'],
            $entries['@graph'],
            $entries['@included'],
        );
        ksort($entries, SORT_STRING);

        foreach ($entries as $property => $value) {
            $property = (string) $property;

            // Step 6.11.1.
            if (self::isBlankNodeIdentifier($property)) {
                $property = $this->issuer->issue($property);
            }

            // Step 6.11.2.
            $node->addProperty($property);

            // Step 6.11.3.
            $this->visit($value, $activeGraph, $id, $property);
        }
    }

    /**
     * Returns the value of an `@type` entry with each blank node identifier in
     * it relabeled, whether the value is a list of types or one type
     */
    private function relabel(mixed $type): mixed
    {
        if (is_array($type)) {
            return array_map($this->relabel(...), $type);
        }

        return is_string($type) && self::isBlankNodeIdentifier($type) ? $this->issuer->issue($type) : $type;
    }

    /**
     * Returns the node of the active subject and the active property, for an
     * item that is the value of a property
     *
     * @return array{Node, string}
     *
     * @throws InvalidArgument if there is no such property, which happens
     *     only when a value object or list object is where expansion never
     *     leaves one: at the top level, inside `@graph` or `@included`, or
     *     under a reverse property
     */
    private function target(
        string $activeGraph,
        string | stdClass | null $activeSubject,
        ?string $activeProperty,
    ): array {
        if (!is_string($activeSubject) || $activeProperty === null) {
            throw new InvalidArgument('A value object or list object must be the value of a property of a node');
        }

        return [$this->nodeMap->node($activeGraph, $activeSubject), $activeProperty];
    }

    private static function isBlankNodeIdentifier(string $value): bool
    {
        return str_starts_with($value, '_:');
    }
}
