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
 * Bounds on the work the processor will do for one document
 *
 * `maxDepth` bounds every recursion in the algorithms: through the document's
 * structure, through contexts that include one another, and through terms that
 * depend on one another.
 *
 * `maxValues` bounds the size of the document. It also controls the cost of
 * copying term definitions. A context that defines a term copies the term
 * definitions of the active context once, and a document's own contexts may
 * define as many terms as `maxValues` allows. A caller who reads untrusted
 * documents can lower `maxValues` to shorten the longest possible run.
 *
 * `maxContextOperations` bounds the work of context processing in one call. An
 * operation is one item of a context, whether a map, a `null`, or a URL, or one
 * term that the processor takes up. A scoped context is processed again for
 * each active context it applies to, and a document may give every node a
 * context of its own, so a small document can ask for many more operations than
 * it has values. These cost nothing: a term that is already defined when the
 * processor reaches it, a scoped context that the cache of processed contexts
 * serves, and the keyword entries of a context definition, such as `@vocab`.
 *
 * To disable a limit, pass `PHP_INT_MAX`.
 */
final readonly class Limits
{
    /**
     * The greatest number of nested arrays and objects; a top-level object is
     * depth 1
     *
     * The same number bounds a chain of contexts that include one another
     * and a chain of terms that depend on one another.
     *
     * @var int<1, max>
     */
    public int $maxDepth;

    /**
     * The greatest number of JSON values of any kind in the document, scalars
     * included
     *
     * @var int<1, max>
     */
    public int $maxValues;

    /**
     * The greatest number of context operations that one call may make, in
     * every context it processes: the document's own contexts, scoped
     * contexts, and contexts from the document loader
     *
     * An operation is one item of a context, whether a map, a `null`, or a
     * URL, or one term that the processor takes up, whether the term ends up
     * defined, defined as `null`, or ignored. A term that is already defined
     * when the processor reaches it, a scoped context that the cache of
     * processed contexts serves, and the keyword entries of a context
     * definition cost nothing.
     *
     * @var int<1, max>
     */
    public int $maxContextOperations;

    /**
     * @throws InvalidArgument if a limit is less than 1
     */
    public function __construct(int $maxDepth = 128, int $maxValues = 100_000, int $maxContextOperations = 1_000_000)
    {
        if ($maxDepth < 1) {
            throw new InvalidArgument(sprintf('maxDepth must be at least 1; %d given', $maxDepth));
        }

        if ($maxValues < 1) {
            throw new InvalidArgument(sprintf('maxValues must be at least 1; %d given', $maxValues));
        }

        if ($maxContextOperations < 1) {
            throw new InvalidArgument(
                sprintf('maxContextOperations must be at least 1; %d given', $maxContextOperations),
            );
        }

        $this->maxDepth = $maxDepth;
        $this->maxValues = $maxValues;
        $this->maxContextOperations = $maxContextOperations;
    }
}
