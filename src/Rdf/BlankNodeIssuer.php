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
 * Issues blank node identifiers for one conversion, and relabels the ones the
 * document brought with it
 *
 * This is the Generate Blank Node Identifier algorithm. An identifier of the
 * document is mapped to a new one the first time it is seen and to the same
 * one after that, so that two mentions of one blank node stay one blank node
 * and never collide with an identifier the issuer made up.
 *
 * @internal
 *
 * @link https://www.w3.org/TR/json-ld11-api/#generate-blank-node-identifier JSON-LD 1.1 API, Generate Blank Node Identifier
 */
final class BlankNodeIssuer
{
    /**
     * A new identifier is the prefix followed by a counter: `_:b0`, `_:b1`,
     * and so on, as the reference processors issue them
     */
    private const string PREFIX = '_:b';

    /**
     * @var array<string, string> The identifier issued for each identifier of
     *     the document
     */
    private array $issued = [];

    private int $counter = 0;

    /**
     * Returns the identifier issued for an identifier of the document, issuing
     * one the first time, or a new identifier when given null
     */
    public function issue(?string $identifier = null): string
    {
        // Step 1.
        if ($identifier !== null && isset($this->issued[$identifier])) {
            return $this->issued[$identifier];
        }

        // Step 2.
        $issued = self::PREFIX . $this->counter;
        $this->counter++;

        // Step 3.
        if ($identifier !== null) {
            $this->issued[$identifier] = $issued;
        }

        // Step 4.
        return $issued;
    }
}
