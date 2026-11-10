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

namespace SocialWeb\JsonLd;

use JsonSerializable;
use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\Context\ContextProcessor;
use SocialWeb\JsonLd\Context\DocumentLoader;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\JsonLd\Exception\RestrictedFeature;
use SocialWeb\JsonLd\Expansion\Expander;
use SocialWeb\JsonLd\Expansion\RestrictionChecker;
use SocialWeb\JsonLd\Rdf\BlankNodeIssuer;
use SocialWeb\JsonLd\Rdf\NodeMapGenerator;
use SocialWeb\JsonLd\Rdf\RdfConverter;
use SocialWeb\Rdf\Dataset;
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
 * by URL comes from the document loader it was given. It is strict by
 * default, so data that the JSON-LD algorithms would silently drop is an
 * error unless `strict` is turned off.
 *
 * The settings are the JSON-LD 1.1 API options that apply to expansion and to
 * conversion to RDF, plus this library's own strict flag, limits, and
 * restrictions. Options that apply only to compaction, framing, conversion
 * from RDF, or HTML are not offered.
 *
 * @link https://www.w3.org/TR/json-ld11-api/#the-jsonldprocessor-interface JSON-LD 1.1 API, The JsonLdProcessor Interface
 */
final class Processor
{
    private readonly DocumentReader $reader;
    private readonly ContextProcessor $contextProcessor;
    private readonly Expander $expander;

    private readonly Settings $settings;

    /**
     * The expand context as the reader returned it, with the `@context` entry
     * of a document taken out, or the context URL as given
     */
    private readonly mixed $expandContext;

    /**
     * The three limits bound the work done for one document. To disable one,
     * pass `PHP_INT_MAX`.
     *
     * The four restrictions each refuse one feature of an expanded document.
     * An RDF dataset does not record the JSON tree it came from, and JSON-LD
     * offers several ways to write the same dataset. A caller that binds
     * something to the dataset, such as a hash, but then interprets the tree,
     * needs the tree to be the only one that produces that dataset. The check
     * runs on the expanded document, where aliases have already been
     * resolved.
     *
     * @param string | null $base The base IRI for resolving relative
     *     references; `null`, the default, leaves them unresolved
     * @param array<mixed> | string | JsonSerializable | stdClass | null $expandContext
     *     A context applied before the document's own. A string is a context
     *     URL, not JSON text, and is loaded when a document is expanded. Any
     *     other value is read once, when the processor is built, as a document
     *     is and under the same limits.
     * @param ProcessingMode $processingMode `JsonLd10` mode turns JSON-LD 1.1
     *     features into processing errors
     * @param RdfDirection | null $rdfDirection How base direction reaches RDF;
     *     `null`, the default, ignores it
     * @param bool $strict If `true`, data the algorithms would drop is treated
     *     as an error
     * @param DocumentLoader $documentLoader Supplies the contexts that
     *     documents reference by URL; the default knows the pinned contexts
     *     and never uses the network
     * @param int $maxDepth The greatest number of nested arrays and objects in
     *     a document; a top-level object is depth 1. The same number bounds a
     *     chain of contexts that include one another and a chain of terms
     *     that depend on one another. A chain of contexts that reference one
     *     another by URL and is longer than this raises `JsonLdError` with the
     *     code `context overflow`, the code the JSON-LD API gives it, and not
     *     `LimitExceeded`.
     * @param int $maxValues The greatest number of JSON values of any kind in
     *     a document, scalars included. It also bounds the cost of copying
     *     term definitions: a context that defines a term copies the term
     *     definitions of the active context once, and a document's own
     *     contexts may define as many terms as `maxValues` allows.
     * @param int $maxContextOperations The greatest number of context
     *     operations that one call may make, in every context it processes:
     *     the document's own contexts, scoped contexts, and contexts from the
     *     document loader. An operation is one item of a context, whether a
     *     map, a `null`, or a URL, or one term that the processor takes up. A
     *     scoped context is processed again for each active context it
     *     applies to, and a document may give every node a context of its
     *     own, so a small document can ask for many more operations than it
     *     has values. These cost nothing: a term that is already defined when
     *     the processor reaches it, a scoped context that the cache of
     *     processed contexts serves, and the keyword entries of a context
     *     definition, such as `@vocab`.
     * @param bool $forbidNamedGraphs Refuse any `@graph` entry in an expanded
     *     node object; equivalent to requiring every quad to be in the
     *     default graph
     * @param bool $forbidIncludedBlocks Refuse any `@included` entry
     * @param bool $forbidReverseProperties Refuse any `@reverse` entry
     * @param bool $requireSingleTopLevelNode Refuse an expanded document whose
     *     top level is not exactly one node object
     *
     * @throws InvalidArgument if the base is given and is not a well-formed
     *     absolute IRI, if a limit is less than 1, or if the expand context
     *     holds a value whose PHP type is not a JSON type. Such a value is
     *     always found when it lies within the limits on depth and on the
     *     number of values, and may be found when it lies beyond them.
     * @throws JsonLdError with the code `loading document failed` if the
     *     expand context is not valid JSON or holds a value that JSON text
     *     cannot express, such as a number that is not finite
     * @throws LimitExceeded if the expand context exceeds `maxDepth` or
     *     `maxValues`
     */
    public function __construct(
        ?string $base = null,
        array | string | JsonSerializable | stdClass | null $expandContext = null,
        ProcessingMode $processingMode = ProcessingMode::JsonLd11,
        ?RdfDirection $rdfDirection = null,
        bool $strict = true,
        DocumentLoader $documentLoader = new BundledDocumentLoader(),
        int $maxDepth = 128,
        int $maxValues = 100_000,
        int $maxContextOperations = 1_000_000,
        bool $forbidNamedGraphs = false,
        bool $forbidIncludedBlocks = false,
        bool $forbidReverseProperties = false,
        bool $requireSingleTopLevelNode = false,
    ) {
        $this->settings = new Settings(
            $base,
            $expandContext,
            $processingMode,
            $rdfDirection,
            $strict,
            $documentLoader,
            $maxDepth,
            $maxValues,
            $maxContextOperations,
            $forbidNamedGraphs,
            $forbidIncludedBlocks,
            $forbidReverseProperties,
            $requireSingleTopLevelNode,
        );
        $this->reader = new DocumentReader($this->settings);

        if ($expandContext !== null && !is_string($expandContext)) {
            $expandContext = $this->reader->read($expandContext, 'expandContext');

            if ($expandContext instanceof stdClass && property_exists($expandContext, '@context')) {
                $expandContext = $expandContext->{'@context'};
            }
        }

        $this->expandContext = $expandContext;
        $this->contextProcessor = new ContextProcessor($this->settings);
        $this->expander = new Expander($this->settings, $this->contextProcessor);
    }

    /**
     * Expands a document
     *
     * Expansion applies the document's contexts and then leaves them out of the
     * result. Every property and type becomes a full IRI, and every value is
     * written in one explicit form, so that two documents that mean the same
     * thing expand to the same result even when their contexts differ.
     *
     * @param array<mixed> | string | JsonSerializable | stdClass $document A
     *     JSON-encoded string; a decoded document, as a `stdClass` tree or as
     *     nested arrays; or a `JsonSerializable`, which is read as what its
     *     `jsonSerialize()` returns. In nested arrays, a PHP array that is a
     *     list, including an empty array, is a JSON array, and any other PHP
     *     array is a JSON object.
     *
     * @throws InvalidArgument if the document holds a value whose PHP type is
     *     not a JSON type, such as an object of a class other than `stdClass`.
     *     Such a value is always found when it lies within the limits on depth
     *     and on the number of values, and may be found when it lies beyond
     *     them.
     * @throws LimitExceeded if the document exceeds a limit, or its contexts
     *     would take more operations to process than the limit allows
     * @throws JsonLdError if the document breaks a rule of the specification,
     *     or, with the code `loading document failed`, if the string is not
     *     valid JSON or the document holds a value that JSON text cannot
     *     express, such as a number that is not finite
     * @throws DataLoss in strict mode, if part of the document would be
     *     dropped
     * @throws RestrictedFeature if the expanded document uses a feature that
     *     the restrictions forbid
     */
    public function expand(array | string | JsonSerializable | stdClass $document): ExpandedDocument
    {
        return new ExpandedDocument($this->expandToNodes($document));
    }

    /**
     * Converts a document to an RDF dataset
     *
     * The document is expanded, unless it is an `ExpandedDocument` already,
     * and the expanded nodes are converted. Every blank node in the result has
     * an identifier of this processor's making, and the dataset holds its quads
     * in one order for a document whatever the order of the document's entries.
     *
     * A document that is not an `ExpandedDocument` is read and expanded under
     * the limits of this processor, as `expand()` does. An `ExpandedDocument`
     * was read and expanded under the limits of the processor that made it,
     * and `toRdf()` checks only the restrictions on it.
     *
     * @param array<mixed> | string | JsonSerializable | stdClass | ExpandedDocument $document
     *     A document as `expand()` takes it, or an `ExpandedDocument`, which
     *     is not expanded again
     *
     * @throws InvalidArgument if the document holds a value whose PHP type is
     *     not a JSON type, or an `ExpandedDocument` built by hand is not in
     *     expanded form. Such a value is always found when it lies within the
     *     limits on depth and on the number of values, and may be found when
     *     it lies beyond them.
     * @throws LimitExceeded if a document that is not an `ExpandedDocument`
     *     exceeds a limit, or its contexts would take more operations to
     *     process than the limit allows
     * @throws JsonLdError if the document breaks a rule of the specification,
     *     among them `conflicting indexes`, which conversion raises when two
     *     parts of the document give one node different `@index` values; or,
     *     with the code `loading document failed`, if the string is not valid
     *     JSON or the document holds a value that JSON text cannot express
     * @throws DataLoss in strict mode, if part of the document would be
     *     dropped, by expansion or by conversion
     * @throws RestrictedFeature if the expanded document uses a feature that
     *     the restrictions forbid, including an `ExpandedDocument` that was
     *     expanded under other settings
     */
    public function toRdf(array | string | JsonSerializable | stdClass | ExpandedDocument $document): Dataset
    {
        if ($document instanceof ExpandedDocument) {
            $nodes = $document->nodes();
            RestrictionChecker::check($this->settings, $nodes);
        } else {
            $nodes = $this->expandToNodes($document);
        }

        $issuer = new BlankNodeIssuer();

        return RdfConverter::convert(
            NodeMapGenerator::generate($nodes, $issuer, $this->settings->strict),
            $issuer,
            $this->settings,
        );
    }

    /**
     * Expands a document and checks the restrictions, for both public methods
     *
     * @param array<mixed> | string | JsonSerializable | stdClass $document
     *
     * @return list<mixed>
     */
    private function expandToNodes(array | string | JsonSerializable | stdClass $document): array
    {
        $this->contextProcessor->reset();

        $element = $this->reader->read($document);
        $activeContext = ActiveContext::initial($this->settings->base);

        if ($this->settings->expandContext !== null) {
            $activeContext = $this->contextProcessor->process(
                $activeContext,
                $this->expandContext,
                $this->settings->base,
            );
        }

        $expanded = $this->expander->expand($activeContext, null, $element, $this->settings->base);

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

        RestrictionChecker::check($this->settings, $nodes);

        return $nodes;
    }
}
