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

use function array_keys;
use function array_map;
use function array_values;
use function ksort;
use function sort;
use function strval;

use const SORT_STRING;

/**
 * The graphs and nodes of an expanded document, as Node Map Generation builds
 * them
 *
 * Each graph holds its nodes by identifier. The default graph is under
 * `@default`, and every other graph under its name.
 *
 * @internal
 *
 * @link https://www.w3.org/TR/json-ld11-api/#node-map-generation JSON-LD 1.1 API, Node Map Generation
 */
final class NodeMap
{
    public const string DEFAULT_GRAPH = '@default';

    /**
     * @var array<string, array<string, Node>> The nodes of each graph, by
     *     graph name and then by identifier
     */
    private array $graphs = [self::DEFAULT_GRAPH => []];

    /**
     * Returns the node with the identifier in the graph, making it if the
     * graph has none
     *
     * The graph is made with the node if the map has no graph of that name.
     */
    public function node(string $graphName, string $id): Node
    {
        return $this->graphs[$graphName][$id] ??= new Node($id);
    }

    /**
     * Returns the names of the graphs in code point order, `@default` among
     * them
     *
     * A graph is in the map once a node has been put in it, so an empty
     * `@graph` entry of the document makes no graph.
     *
     * @return list<string>
     */
    public function graphNames(): array
    {
        $names = array_map(strval(...), array_keys($this->graphs));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Returns the nodes of a graph in code point order of their identifiers
     *
     * @return list<Node>
     */
    public function nodes(string $graphName): array
    {
        $nodes = $this->graphs[$graphName] ?? [];
        ksort($nodes, SORT_STRING);

        return array_values($nodes);
    }
}
