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

namespace SocialWeb\JsonLd\Context;

use WeakMap;

use function count;
use function spl_object_id;
use function sprintf;

/**
 * Results of context processing, kept so that a context is processed once for
 * each active context it applies to
 *
 * `ActiveContext` and `TermDefinition` are immutable, and a context processor
 * remembers each document it has loaded. So the same context, applied to the
 * same active context with the same flags, always gives the same result, and
 * the result can be kept.
 *
 * Two kinds of context have an identity that can be looked up: the scoped
 * context of a term definition, and a context named by URL. A context written
 * inline as a map has none and is not kept.
 *
 * Results are held in a `WeakMap` by active context, so the results for an
 * active context are released with it. A result that does not propagate refers
 * to the active context it was made from, as its previous context. PHP releases
 * such a result only when its cycle collector runs or when `clear()` is
 * called.
 *
 * @internal
 */
final class ProcessedContextCache
{
    /**
     * The greatest number of term definitions that the results kept at one
     * time may hold between them
     */
    public const int MAX_TERM_DEFINITIONS = 100_000;

    /**
     * The results for each active context, by key
     *
     * A result for a scoped context is kept with its term definition. The key
     * is made from the object identifier of the term definition, and PHP gives
     * the identifier of an object it has released to the next new object.
     * While the term definition is held here, that cannot happen.
     *
     * @var WeakMap<ActiveContext, array<string, array{ActiveContext, TermDefinition | null}>>
     */
    private WeakMap $results;

    /**
     * The number of term definitions in the results stored since the last
     * call to `clear()`
     */
    private int $termDefinitions = 0;

    /**
     * @param int $maxTermDefinitions The greatest number of term definitions
     *     that the results may hold between them; past it, nothing is stored
     */
    public function __construct(private readonly int $maxTermDefinitions = self::MAX_TERM_DEFINITIONS)
    {
        $this->results = new WeakMap();
    }

    /**
     * Returns the result of applying the scoped context of a term definition
     * to an active context, or `null` if it is not stored
     */
    public function scoped(
        ActiveContext $activeContext,
        TermDefinition $definition,
        bool $overrideProtected,
        bool $propagate,
    ): ?ActiveContext {
        $key = self::scopedKey($definition, $overrideProtected, $propagate);

        return $this->results[$activeContext][$key][0] ?? null;
    }

    /**
     * Stores the result of applying the scoped context of a term definition
     * to an active context
     */
    public function storeScoped(
        ActiveContext $activeContext,
        TermDefinition $definition,
        bool $overrideProtected,
        bool $propagate,
        ActiveContext $result,
    ): void {
        $this->store(
            $activeContext,
            self::scopedKey($definition, $overrideProtected, $propagate),
            $result,
            $definition,
        );
    }

    /**
     * Returns the result of applying the context at a URL to an active
     * context, or `null` if it is not stored
     */
    public function remote(ActiveContext $activeContext, string $url, bool $overrideProtected): ?ActiveContext
    {
        return $this->results[$activeContext][self::remoteKey($url, $overrideProtected)][0] ?? null;
    }

    /**
     * Stores the result of applying the context at a URL to an active context
     */
    public function storeRemote(
        ActiveContext $activeContext,
        string $url,
        bool $overrideProtected,
        ActiveContext $result,
    ): void {
        $this->store($activeContext, self::remoteKey($url, $overrideProtected), $result, null);
    }

    /**
     * Returns the number of term definitions in the results stored since the
     * last call to `clear()`
     */
    public function termDefinitions(): int
    {
        return $this->termDefinitions;
    }

    /**
     * Releases every result
     */
    public function clear(): void
    {
        $this->results = new WeakMap();
        $this->termDefinitions = 0;
    }

    /**
     * Stores a result, unless it would take the number of term definitions
     * held past the cap
     */
    private function store(
        ActiveContext $activeContext,
        string $key,
        ActiveContext $result,
        ?TermDefinition $definition,
    ): void {
        $termDefinitions = $this->termDefinitions + count($result->termDefinitions);

        if ($termDefinitions > $this->maxTermDefinitions) {
            return;
        }

        $this->results[$activeContext] ??= [];
        $this->results[$activeContext][$key] = [$result, $definition];
        $this->termDefinitions = $termDefinitions;
    }

    /**
     * Returns the key for the scoped context of a term definition
     *
     * The key begins with a letter that the key for a URL does not begin with,
     * so the two kinds of key are never the same.
     */
    private static function scopedKey(TermDefinition $definition, bool $overrideProtected, bool $propagate): string
    {
        return sprintf('t%d %d%d', spl_object_id($definition), $overrideProtected, $propagate);
    }

    /**
     * Returns the key for a context named by URL
     */
    private static function remoteKey(string $url, bool $overrideProtected): string
    {
        return sprintf('u%d %s', $overrideProtected, $url);
    }
}
