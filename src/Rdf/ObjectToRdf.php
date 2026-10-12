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

use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Grammar;
use SocialWeb\JsonLd\RdfDirection;
use SocialWeb\Rdf\BlankNode;
use SocialWeb\Rdf\GraphName;
use SocialWeb\Rdf\Iri;
use SocialWeb\Rdf\Literal;
use SocialWeb\Rdf\Quad;
use SocialWeb\Rdf\Term;
use SocialWeb\Rdf\Vocabulary\Rdf;
use SocialWeb\Rdf\Vocabulary\Xsd;
use stdClass;

use function array_is_list;
use function array_key_exists;
use function get_object_vars;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * Turns a value object, a list object, or a node object into the RDF term
 * that is the object of a triple
 *
 * This is the Object to RDF Conversion algorithm. A value object becomes a
 * literal, a list object becomes the head of an RDF collection, and a node
 * object becomes the IRI or blank node its identifier names. The conversion
 * returns null, after the strict mode check, where the algorithm says the
 * triple is to be ignored because a term in it is not well-formed.
 *
 * @internal
 *
 * @link https://www.w3.org/TR/json-ld11-api/#object-to-rdf-conversion JSON-LD 1.1 API, Object to RDF Conversion
 */
final class ObjectToRdf
{
    private readonly ListToRdf $lists;

    public function __construct(
        private readonly BlankNodeIssuer $issuer,
        private readonly bool $strict,
        private readonly ?RdfDirection $rdfDirection,
    ) {
        $this->lists = new ListToRdf($this, $issuer);
    }

    /**
     * Returns the term for an item of a node map, or null if the item is a
     * node object whose identifier is not well-formed or a value object whose
     * datatype or language tag is not well-formed
     *
     * @param list<Quad> $triples The triples that a list or a compound literal
     *     adds, in the algorithm's "list triples", for the caller to add to the
     *     dataset after the triple that holds the returned term
     *
     * @throws DataLoss in strict mode, where the algorithm would ignore the
     *     triple
     * @throws InvalidArgument if the item is not a value object, list object,
     *     or node object of expanded form
     */
    public function convert(mixed $item, GraphName $graph, array &$triples): ?Term
    {
        if (!$item instanceof stdClass) {
            throw new InvalidArgument('An item of a node map must be a map');
        }

        $entries = get_object_vars($item);

        // Step 3.
        if (array_key_exists('@list', $entries)) {
            $list = $entries['@list'];

            if (!is_array($list) || !array_is_list($list)) {
                throw new InvalidArgument('The value of @list must be an array');
            }

            return $this->lists->convert($list, $graph, $triples);
        }

        // Steps 1 and 2.
        if (!array_key_exists('@value', $entries)) {
            $id = $entries['@id'] ?? null;

            if (!is_string($id)) {
                throw new InvalidArgument('A node object in a node map must have an @id entry that is a string');
            }

            return $this->resource($id, DataLossCondition::RelativeObject);
        }

        // Step 4. The other entries are read here too, and checked against the
        // shapes expansion leaves, so that a value object built by hand ends in
        // InvalidArgument and never in a PHP error.
        $value = $entries['@value'];
        $datatype = $entries['@type'] ?? null;
        $language = $entries['@language'] ?? null;
        $direction = $entries['@direction'] ?? null;

        if ($datatype !== null && !is_string($datatype)) {
            throw new InvalidArgument('The @type of a value object must be a string');
        }

        if (($language !== null && !is_string($language)) || ($direction !== null && !is_string($direction))) {
            throw new InvalidArgument('The @language and @direction of a value object must be strings');
        }

        if (($language !== null || $direction !== null) && ($datatype !== null || !is_string($value))) {
            throw new InvalidArgument(
                'Only a value object whose @value is a string and that has no @type may have @language or @direction',
            );
        }

        // Step 5 is the datatype, read above. Step 6.
        if ($datatype !== null && $datatype !== '@json' && !Grammar::isWellFormedIri($datatype)) {
            return $this->drop(DataLossCondition::MalformedDatatype, $datatype);
        }

        // Step 7.
        if ($language !== null && !Grammar::isWellFormedLanguageTag($language)) {
            return $this->drop(DataLossCondition::MalformedLanguageTag, $language);
        }

        if ($datatype === '@json') {
            // Step 8.
            $lexicalForm = JsonCanonicalizer::canonicalize($value);
            $datatype = Vocabulary::RDF_JSON;
        } elseif (is_bool($value)) {
            // Step 9.
            $lexicalForm = LexicalForms::boolean($value);
            $datatype ??= Vocabulary::XSD_BOOLEAN;
        } elseif (is_int($value) || is_float($value)) {
            if (LexicalForms::isDouble($value) || $datatype === Vocabulary::XSD_DOUBLE) {
                // Step 10.
                $lexicalForm = LexicalForms::double($value);
                $datatype ??= Vocabulary::XSD_DOUBLE;
            } else {
                // Step 11.
                $lexicalForm = LexicalForms::integer($value);
                $datatype ??= Vocabulary::XSD_INTEGER;
            }
        } elseif (is_string($value)) {
            // Step 12. The reader of the document has refused every string
            // that is not valid UTF-8; a value object built by hand may hold
            // one, and a literal cannot.
            if (preg_match('//u', $value) !== 1) {
                throw new InvalidArgument('The @value of a value object must be valid UTF-8');
            }

            $lexicalForm = $value;
            $datatype ??= $language !== null ? Rdf::LANG_STRING : Xsd::STRING;
        } else {
            throw new InvalidArgument(
                'The @value of a value object must be a string, a number, or a boolean, unless its @type is @json',
            );
        }

        // Step 13.
        if ($direction !== null) {
            if ($this->rdfDirection === null) {
                $this->drop(DataLossCondition::DroppedDirection, JsonCanonicalizer::canonicalize($item));
            } else {
                // Step 13.1.
                $language = strtolower($language ?? '');

                // Step 13.2.
                if ($this->rdfDirection === RdfDirection::I18nDatatype) {
                    return new Literal($lexicalForm, new Iri(Vocabulary::I18N . $language . '_' . $direction));
                }

                // Step 13.3.
                $literal = new BlankNode(substr($this->issuer->issue(), 2));
                $triples[] = new Quad($literal, new Iri(Vocabulary::RDF_VALUE), new Literal($lexicalForm), $graph);

                if (array_key_exists('@language', $entries)) {
                    $triples[] = new Quad($literal, new Iri(Vocabulary::RDF_LANGUAGE), new Literal($language), $graph);
                }

                $triples[] = new Quad($literal, new Iri(Vocabulary::RDF_DIRECTION), new Literal($direction), $graph);

                return $literal;
            }
        }

        // Steps 14 and 15.
        return new Literal($lexicalForm, new Iri($datatype), $language);
    }

    /**
     * Returns the IRI or blank node that an identifier names, or null, after
     * the strict mode check, if the identifier is neither a well-formed IRI
     * nor a well-formed blank node identifier
     *
     * @param DataLossCondition $ifRelative The condition to raise for an IRI
     *     that is not well-formed, which names the position the identifier is
     *     in; a blank node identifier that is not well-formed raises
     *     `malformed blank node identifier` whatever its position
     *
     * @throws DataLoss in strict mode, if the identifier is not well-formed
     */
    public function resource(string $identifier, DataLossCondition $ifRelative): Iri | BlankNode | null
    {
        if (str_starts_with($identifier, '_:')) {
            $label = substr($identifier, 2);

            return Grammar::isWellFormedBlankNodeIdentifier($label)
                ? new BlankNode($label)
                : $this->drop(DataLossCondition::MalformedBlankNodeIdentifier, $identifier);
        }

        return Grammar::isWellFormedIri($identifier) ? new Iri($identifier) : $this->drop($ifRelative, $identifier);
    }

    /**
     * In strict mode, refuses to drop what the algorithm drops silently; in
     * lenient mode, returns the null that stands for the dropped term
     */
    private function drop(DataLossCondition $condition, string $detail): null
    {
        if ($this->strict) {
            throw new DataLoss($condition, $detail);
        }

        return null;
    }
}
