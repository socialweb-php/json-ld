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

use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\ContextProcessor;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\JsonLd\Exception\MalformedJson;
use SocialWeb\JsonLd\Exception\RestrictedFeature;
use SocialWeb\JsonLd\Expansion\Expander;
use SocialWeb\JsonLd\Expansion\RestrictionChecker;
use stdClass;

use function array_is_list;
use function array_keys;
use function get_object_vars;
use function is_array;
use function is_string;
use function property_exists;

/**
 * A JSON-LD 1.1 processor
 *
 * The processor never uses the network: a context that a document references
 * by URL comes from the document loader in the options. It is strict by
 * default, so data that the JSON-LD algorithms would silently drop is an
 * error unless the options say otherwise.
 */
final class Processor
{
    private readonly DocumentReader $reader;
    private readonly ContextProcessor $contextProcessor;
    private readonly Expander $expander;

    public function __construct(private readonly Options $options = new Options())
    {
        $this->reader = new DocumentReader($options->limits);
        $this->contextProcessor = new ContextProcessor($options);
        $this->expander = new Expander($options, $this->contextProcessor);
    }

    /**
     * Expands a document
     *
     * Expansion applies the document's contexts and then leaves them out of the
     * result. Every property and type becomes a full IRI, and every value is
     * written in one explicit form, so that two documents that mean the same
     * thing expand to the same result even when their contexts differ.
     *
     * @link https://www.w3.org/TR/json-ld11-api/#dom-jsonldprocessor-expand JSON-LD 1.1 API, expand()
     *
     * @param string | array<mixed> | object $document A JSON-encoded string;
     *     or a decoded document, as a `stdClass` tree or as nested arrays. In
     *     nested arrays, a PHP array that is a list, including an empty array,
     *     is a JSON array, and any other PHP array is a JSON object.
     *
     * @throws MalformedJson if the string is not valid JSON, or the document
     *     holds a value that JSON cannot represent
     * @throws InvalidArgument if the document holds something that is not a
     *     JSON value
     * @throws LimitExceeded if the document exceeds a limit
     * @throws JsonLdError if the document breaks a rule of the specification
     * @throws DataLoss in strict mode, if part of the document would be
     *     dropped
     * @throws RestrictedFeature if the expanded document uses a feature that
     *     the restrictions forbid
     */
    public function expand(string | array | object $document): ExpandedDocument
    {
        $element = $this->reader->read($document);
        $activeContext = ActiveContext::initial($this->options->base);
        $expandContext = $this->options->expandContext;

        if ($expandContext !== null) {
            if (!is_string($expandContext)) {
                $expandContext = $this->reader->read($expandContext);
            }

            if ($expandContext instanceof stdClass && property_exists($expandContext, '@context')) {
                $expandContext = $expandContext->{'@context'};
            }

            $activeContext = $this->contextProcessor->process($activeContext, $expandContext, $this->options->base);
        }

        $expanded = $this->expander->expand($activeContext, null, $element, $this->options->base);

        // If the result is a map whose only entry is `@graph`, the value of
        // that entry takes its place. Such a map only groups the nodes of the
        // default graph and says nothing of its own.
        if ($expanded instanceof stdClass && array_keys(get_object_vars($expanded)) === ['@graph']) {
            $expanded = $expanded->{'@graph'};
        }

        $nodes = [];

        if ($expanded instanceof stdClass) {
            $nodes = [$expanded];
        } elseif (is_array($expanded) && array_is_list($expanded)) {
            $nodes = $expanded;
        }

        RestrictionChecker::check($this->options->restrictions, $nodes);

        return new ExpandedDocument($nodes);
    }
}
