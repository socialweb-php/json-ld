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

namespace SocialWeb\JsonLd\Expansion;

use SocialWeb\JsonLd\Context\ActiveContext;
use SocialWeb\JsonLd\Context\ContextProcessor;
use SocialWeb\JsonLd\Context\IriExpander;
use SocialWeb\JsonLd\Context\TermDefinition;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\JsonLd\Grammar;
use SocialWeb\JsonLd\Keywords;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\ProcessingMode;
use SocialWeb\JsonLd\Rdf\JsonCanonicalizer;
use stdClass;

use function array_diff;
use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_key_last;
use function array_keys;
use function array_map;
use function count;
use function get_object_vars;
use function is_array;
use function is_string;
use function ksort;
use function sort;
use function str_contains;
use function strtolower;

use const SORT_STRING;

/**
 * The Expansion Algorithm, JSON-LD 1.1 Processing Algorithms and API section
 * 5.1
 *
 * The step numbers in the comments are the specification's. Where a comment
 * says the code follows "the reference processors" and not the text, it means
 * jsonld.js and the Ruby json-ld gem, the two implementations this one was
 * checked against.
 *
 * The element arrives in the library's internal form, JSON objects as
 * `stdClass` and JSON arrays as lists, and the result is in the same form. Map
 * entries are always visited in code point order, which the specification makes
 * optional, so that the output does not depend on how the input was written.
 * The steps that apply only to framing are left out.
 *
 * In strict mode, a step that drops data raises `DataLoss` instead. Three
 * things are dropped without it: a property whose value is null, the `@index`
 * of a set object, and the key of an identifier map whose item has an `@id` of
 * its own.
 *
 * @internal
 */
final class Expander
{
    /**
     * The entries a value object may have
     */
    private const array VALUE_OBJECT_KEYWORDS = ['@direction', '@index', '@language', '@type', '@value'];

    public function __construct(
        private readonly Options $options,
        private readonly ContextProcessor $contextProcessor,
    ) {
    }

    /**
     * Returns the element expanded: a map, a list, or `null`
     *
     * @param string | null $activeProperty The key whose value the element is.
     *     For the value of an `@graph` or `@reverse` entry, it is that keyword.
     *     At the top level and for the value of an `@included` entry, it is
     *     `null`.
     * @param mixed $element The element, in the internal form
     * @param string | null $baseUrl The base for resolving context URLs
     * @param bool $fromMap Whether the element is an item of an index, type,
     *     or identifier map. For such an item, step 7 keeps the active context
     *     where it would otherwise go back to the previous one.
     *
     * @return stdClass | list<mixed> | null
     *
     * @throws JsonLdError if the element breaks a rule of the specification
     * @throws DataLoss in strict mode, if part of the element would be dropped
     * @throws LimitExceeded if a context exceeds the depth limit
     */
    public function expand(
        ActiveContext $activeContext,
        ?string $activeProperty,
        mixed $element,
        ?string $baseUrl,
        bool $fromMap = false,
    ): stdClass | array | null {
        // Step 1.
        if ($element === null) {
            return null;
        }

        // Step 5.
        if (is_array($element)) {
            return $this->expandArray($activeContext, $activeProperty, $element, $baseUrl, $fromMap);
        }

        // Step 3.
        $propertyDefinition = $activeProperty !== null ? $activeContext->termDefinition($activeProperty) : null;

        // Step 4.
        if (!$element instanceof stdClass) {
            // Step 4.1.
            if ($activeProperty === null || $activeProperty === '@graph') {
                $this->drop(DataLossCondition::FreeFloatingNode, $element);

                return null;
            }

            // Step 4.2. The specification lets a property-scoped context
            // redefine protected terms in step 8, where the value is a map, and
            // says nothing of it here, where the value is a scalar. This code
            // allows it in both, as the reference processors do. Otherwise, the
            // same context would be accepted for one kind of value and refused
            // for the other.
            if ($propertyDefinition !== null && $propertyDefinition->hasContext) {
                $activeContext = $this->contextProcessor->process(
                    $activeContext,
                    $propertyDefinition->context,
                    $propertyDefinition->baseUrl,
                    overrideProtected: true,
                );
            }

            // Step 4.3.
            return $this->expandValue($activeContext, $activeProperty, $element);
        }

        // Step 6.
        $entries = get_object_vars($element);
        ksort($entries, SORT_STRING);

        // Step 7.
        if ($activeContext->previousContext !== null && !$fromMap && $this->startsNewNode($activeContext, $entries)) {
            $activeContext = $activeContext->previousContext;
        }

        // Step 8.
        if ($propertyDefinition !== null && $propertyDefinition->hasContext) {
            $activeContext = $this->contextProcessor->process(
                $activeContext,
                $propertyDefinition->context,
                $propertyDefinition->baseUrl,
                overrideProtected: true,
            );
        }

        // Step 9.
        if (array_key_exists('@context', $entries)) {
            $activeContext = $this->contextProcessor->process($activeContext, $entries['@context'], $baseUrl);
        }

        // Step 10.
        $typeScopedContext = $activeContext;

        // Steps 11 and 12.
        $sawTypeEntry = false;
        $inputTypeCandidate = null;

        foreach ($entries as $key => $value) {
            if (!self::expandsTo($activeContext, (string) $key, '@type')) {
                continue;
            }

            // Step 11.1.
            $terms = is_array($value) ? $value : [$value];

            // Step 12, first part. The input type comes from the first key that
            // expands to `@type`: it is the last of that key's values. This
            // loop already visits those keys in order, so the value is picked
            // here. It is expanded after the loop.
            if (!$sawTypeEntry) {
                $sawTypeEntry = true;
                $inputTypeCandidate = $terms === [] ? null : $terms[array_key_last($terms)];
            }

            // Step 11.2.
            $terms = array_filter($terms, is_string(...));
            sort($terms, SORT_STRING);

            foreach ($terms as $term) {
                $definition = $typeScopedContext->termDefinition($term);

                if ($definition !== null && $definition->hasContext) {
                    $activeContext = $this->contextProcessor->process(
                        $activeContext,
                        $definition->context,
                        $definition->baseUrl,
                        propagate: false,
                    );
                }
            }
        }

        // Step 12, second part. The value picked above is expanded now, with
        // the active context as the type-scoped contexts have left it. Its only
        // use is to tell whether the value is a JSON literal.
        $isJsonLiteral = is_string($inputTypeCandidate)
            && self::expandsTo($activeContext, $inputTypeCandidate, '@json');

        $result = new ExpansionResult();

        // Steps 13 and 14.
        $this->expandEntries(
            $result,
            $activeContext,
            $typeScopedContext,
            $activeProperty,
            $entries,
            $isJsonLiteral,
            $baseUrl,
        );

        // Steps 15 through 20.
        return $this->finish($result->entries(), $activeProperty);
    }

    /**
     * Step 5: expands an array
     *
     * Each item is expanded in turn. An item that expands to a list has its
     * items added one by one, so the result is never nested. Under a property
     * whose container is `@list`, such a list becomes a list object instead. An
     * item that expands to null is left out.
     *
     * @param array<mixed> $element
     *
     * @return list<mixed>
     */
    private function expandArray(
        ActiveContext $activeContext,
        ?string $activeProperty,
        array $element,
        ?string $baseUrl,
        bool $fromMap,
    ): array {
        $definition = $activeProperty !== null ? $activeContext->termDefinition($activeProperty) : null;
        $isList = $definition !== null && $definition->hasContainer('@list');

        // Step 5.1.
        $result = [];

        // Step 5.2.
        foreach ($element as $item) {
            // Step 5.2.1.
            $expandedItem = $this->expand($activeContext, $activeProperty, $item, $baseUrl, $fromMap);

            // Step 5.2.2.
            if ($isList && is_array($expandedItem)) {
                $expandedItem = (object) ['@list' => $expandedItem];
            }

            // Step 5.2.3.
            if (is_array($expandedItem)) {
                $result = [...$result, ...$expandedItem];
            } elseif ($expandedItem !== null) {
                $result[] = $expandedItem;
            }
        }

        // Step 5.3.
        return $result;
    }

    /**
     * Step 7: whether the element begins a new node object
     *
     * A context that does not propagate applies to one node object, so step 7
     * goes back to the previous context when a new one begins. A map begins a
     * new node object unless it is a value object (it has an entry that expands
     * to `@value`) or a reference to a node (its only entry expands to `@id`).
     *
     * @param array<mixed> $entries
     */
    private function startsNewNode(ActiveContext $activeContext, array $entries): bool
    {
        foreach (array_keys($entries) as $key) {
            if (
                self::expandsTo($activeContext, (string) $key, '@value')
                || (self::expandsTo($activeContext, (string) $key, '@id') && count($entries) === 1)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Steps 13 and 14: expands the entries of a map into the result
     *
     * Step 13 expands the map's own entries. Step 14 does the same for each map
     * found under `@nest`, and adds its entries to the same result, as if they
     * had been written in the outer map.
     *
     * @param array<mixed> $entries The entries of the element, in code point
     *     order
     * @param bool $isJsonLiteral Whether the input type is `@json`
     */
    private function expandEntries(
        ExpansionResult $result,
        ActiveContext $activeContext,
        ActiveContext $typeScopedContext,
        ?string $activeProperty,
        array $entries,
        bool $isJsonLiteral,
        ?string $baseUrl,
    ): void {
        $nests = [];

        // Step 13.
        foreach ($entries as $key => $value) {
            $key = (string) $key;

            // Step 13.1.
            if ($key === '@context') {
                continue;
            }

            // Step 13.2.
            $expandedProperty = IriExpander::expand($activeContext, $key, vocab: true);

            // Step 13.3.
            if ($expandedProperty === null) {
                $this->dropNullExpansion($key);

                continue;
            }

            // Step 13.4.
            if (Keywords::isKeyword($expandedProperty)) {
                // Step 13.4.1.
                if ($activeProperty === '@reverse') {
                    throw new JsonLdError(ErrorCode::InvalidReversePropertyMap, $key);
                }

                if ($expandedProperty === '@nest') {
                    // Step 13.4.14.
                    $nests[] = $key;
                } else {
                    $this->expandKeyword(
                        $result,
                        $activeContext,
                        $typeScopedContext,
                        $activeProperty,
                        $key,
                        $expandedProperty,
                        $value,
                        $isJsonLiteral,
                        $baseUrl,
                    );
                }

                // Step 13.4.17.
                continue;
            }

            // Step 13.3, for a key that is neither an IRI nor a keyword.
            if (!str_contains($expandedProperty, ':')) {
                $this->drop(DataLossCondition::UndefinedProperty, $key);

                continue;
            }

            $definition = $activeContext->termDefinition($key);

            // Steps 13.5 through 13.9.
            $expandedValue = $this->expandPropertyValue($activeContext, $key, $definition, $value, $baseUrl);

            // Step 13.10.
            if ($expandedValue === null) {
                continue;
            }

            // Step 13.11.
            if (
                $definition !== null
                && $definition->hasContainer('@list')
                && !ObjectForms::isListObject($expandedValue)
            ) {
                $expandedValue = (object) ['@list' => self::asList($expandedValue)];
            }

            // Step 13.12.
            if (
                $definition !== null
                && $definition->hasContainer('@graph')
                && !$definition->hasContainer('@id')
                && !$definition->hasContainer('@index')
            ) {
                $expandedValue = array_map(
                    static fn (mixed $item): stdClass => (object) ['@graph' => self::asList($item)],
                    self::asList($expandedValue),
                );
            }

            if ($definition !== null && $definition->reverse) {
                // Step 13.13.
                foreach (self::asList($expandedValue) as $item) {
                    if (ObjectForms::isValueObject($item) || ObjectForms::isListObject($item)) {
                        throw new JsonLdError(ErrorCode::InvalidReversePropertyValue, $key);
                    }

                    $result->addReverse($expandedProperty, $item);
                }
            } else {
                // Step 13.14.
                $result->add($expandedProperty, $expandedValue);
            }
        }

        // Step 14.
        foreach ($nests as $nestingKey) {
            // A term can be an alias of `@nest`, and that term can have a
            // scoped context. The specification does not cover this case. This
            // code applies the scoped context to the entries nested under the
            // term, which W3C tests #tc037 and #tc038 expect.
            $nestContext = $activeContext;
            $nestDefinition = $activeContext->termDefinition($nestingKey);

            if ($nestDefinition !== null && $nestDefinition->hasContext) {
                $nestContext = $this->contextProcessor->process(
                    $activeContext,
                    $nestDefinition->context,
                    $nestDefinition->baseUrl,
                    overrideProtected: true,
                );
            }

            // Steps 14.1 and 14.2.
            foreach (self::asList($entries[$nestingKey]) as $nestedValue) {
                $nestedEntries = $nestedValue instanceof stdClass ? get_object_vars($nestedValue) : null;

                // Step 14.2.1.
                if ($nestedEntries === null || $this->hasValueEntry($nestContext, $nestedEntries)) {
                    throw new JsonLdError(ErrorCode::InvalidNestValue, $nestingKey);
                }

                // Step 14.2.2.
                ksort($nestedEntries, SORT_STRING);
                $this->expandEntries(
                    $result,
                    $nestContext,
                    $typeScopedContext,
                    $activeProperty,
                    $nestedEntries,
                    $isJsonLiteral,
                    $baseUrl,
                );
            }
        }
    }

    /**
     * Steps 13.4.2 through 13.4.16: expands an entry whose key is a keyword
     *
     * The key may be the keyword itself or an alias of it. An `@nest` entry
     * does not come here; it is set aside for step 14.
     */
    private function expandKeyword(
        ExpansionResult $result,
        ActiveContext $activeContext,
        ActiveContext $typeScopedContext,
        ?string $activeProperty,
        string $key,
        string $expandedProperty,
        mixed $value,
        bool $isJsonLiteral,
        ?string $baseUrl,
    ): void {
        $isJsonLd10 = $this->options->processingMode === ProcessingMode::JsonLd10;

        // Step 13.4.2.
        if (
            $result->has($expandedProperty)
            && ($isJsonLd10 || ($expandedProperty !== '@included' && $expandedProperty !== '@type'))
        ) {
            throw new JsonLdError(ErrorCode::CollidingKeywords, $expandedProperty);
        }

        switch ($expandedProperty) {
            case '@id':
                // Step 13.4.3.
                if (!is_string($value)) {
                    throw new JsonLdError(ErrorCode::InvalidIdValue);
                }

                $expandedValue = IriExpander::expand($activeContext, $value, documentRelative: true);

                // The value has the form of a keyword. The entry stays as null.
                if ($expandedValue === null) {
                    $this->drop(DataLossCondition::ReservedTerm, $value);
                }

                break;
            case '@type':
                // Step 13.4.4.
                $expandedValue = $this->expandTypes($typeScopedContext, $value);

                if ($result->has('@type')) {
                    $expandedValue = [...self::asList($result->get('@type')), ...self::asList($expandedValue)];
                }

                break;
            case '@graph':
                // Step 13.4.5.
                $expandedValue = self::asList($this->expand($activeContext, '@graph', $value, $baseUrl) ?? []);

                break;
            case '@included':
                // Step 13.4.6. In `json-ld-1.0` mode, `@included` is a keyword
                // the algorithm has no step for, so the entry is dropped.
                if ($isJsonLd10) {
                    $this->drop(DataLossCondition::UndefinedProperty, $key);

                    return;
                }

                // Expansion returns null when it drops the whole value, as it
                // does here for a string, a value object, or a list object. The
                // null is kept as one item. It is not a node object, so the
                // check below raises `invalid @included value`, which W3C tests
                // #tin07 through #tin09 expect.
                $expandedValue = $this->expand($activeContext, null, $value, $baseUrl);
                $expandedValue = is_array($expandedValue) ? $expandedValue : [$expandedValue];

                foreach ($expandedValue as $item) {
                    if (!ObjectForms::isNodeObject($item)) {
                        throw new JsonLdError(ErrorCode::InvalidIncludedValue);
                    }
                }

                if ($result->has('@included')) {
                    $expandedValue = [...self::asList($result->get('@included')), ...$expandedValue];
                }

                break;
            case '@value':
                // Step 13.4.7. A null `@value` is kept in the result. Its
                // presence marks the map as a value object, so that `@type` is
                // read as a datatype and not as the type of a node. Step 15.3
                // drops a value object whose value is null.
                if ($isJsonLiteral) {
                    // Step 13.4.7.1.
                    if ($isJsonLd10) {
                        throw new JsonLdError(ErrorCode::InvalidValueObjectValue);
                    }
                } elseif (is_array($value) || $value instanceof stdClass) {
                    // Step 13.4.7.2.
                    throw new JsonLdError(ErrorCode::InvalidValueObjectValue);
                }

                $expandedValue = $value;

                break;
            case '@language':
                // Step 13.4.8. The tag is lowercased, which the specification
                // allows.
                if (!is_string($value)) {
                    throw new JsonLdError(ErrorCode::InvalidLanguageTaggedString);
                }

                $expandedValue = strtolower($value);

                break;
            case '@direction':
                // Step 13.4.9. In `json-ld-1.0` mode, `@direction` is a keyword
                // the algorithm has no step for, so the entry is dropped.
                if ($isJsonLd10) {
                    $this->drop(DataLossCondition::UndefinedProperty, $key);

                    return;
                }

                if ($value !== 'ltr' && $value !== 'rtl') {
                    throw new JsonLdError(ErrorCode::InvalidBaseDirection);
                }

                $expandedValue = $value;

                break;
            case '@index':
                // Step 13.4.10.
                if (!is_string($value)) {
                    throw new JsonLdError(ErrorCode::InvalidIndexValue);
                }

                $expandedValue = $value;

                break;
            case '@list':
                // Step 13.4.11.
                if ($activeProperty === null || $activeProperty === '@graph') {
                    $this->drop(DataLossCondition::FreeFloatingNode, $value);

                    return;
                }

                $expandedValue = self::asList($this->expand($activeContext, $activeProperty, $value, $baseUrl) ?? []);

                break;
            case '@set':
                // Step 13.4.12.
                $expandedValue = $this->expand($activeContext, $activeProperty, $value, $baseUrl);

                break;
            case '@reverse':
                // Step 13.4.13.
                $this->expandReverse($result, $activeContext, $value, $baseUrl);

                return;
            default:
                // The specification has no step for any other keyword, such
                // as `@container`, so the entry is dropped.
                $this->drop(DataLossCondition::UndefinedProperty, $key);

                return;
        }

        // Step 13.4.16.
        $result->set($expandedProperty, $expandedValue);
    }

    /**
     * Step 13.4.4: expands the values of `@type`
     *
     * They are expanded with the active context as it was before step 11
     * applied the scoped contexts of these types. The specification calls
     * that earlier context the "type-scoped context".
     *
     * @return string | list<string | null> | null A string stays a string
     */
    private function expandTypes(ActiveContext $typeScopedContext, mixed $value): string | array | null
    {
        if (is_string($value)) {
            return $this->expandType($typeScopedContext, $value);
        }

        if (!is_array($value)) {
            throw new JsonLdError(ErrorCode::InvalidTypeValue);
        }

        $types = [];

        foreach ($value as $type) {
            if (!is_string($type)) {
                throw new JsonLdError(ErrorCode::InvalidTypeValue);
            }

            $types[] = $this->expandType($typeScopedContext, $type);
        }

        return $types;
    }

    /**
     * Step 13.4.4: expands one value of `@type`
     *
     * IRI expansion returns null for a value that has the form of a keyword but
     * is not one, and for a term defined as null. Lenient mode keeps the null
     * in the result. Strict mode raises `DataLoss`.
     */
    private function expandType(ActiveContext $typeScopedContext, string $value): ?string
    {
        $expandedValue = IriExpander::expand($typeScopedContext, $value, documentRelative: true, vocab: true);

        if ($expandedValue === null) {
            $this->dropNullExpansion($value);
        }

        return $expandedValue;
    }

    /**
     * Step 13.4.13: expands the value of `@reverse` into the result
     *
     * The value is a map from properties to nodes that point at this node
     * through those properties, so its entries go into the result's reverse
     * map. An `@reverse` entry inside the value turns the direction around
     * twice, so its entries go into the result as ordinary properties.
     */
    private function expandReverse(
        ExpansionResult $result,
        ActiveContext $activeContext,
        mixed $value,
        ?string $baseUrl,
    ): void {
        // Step 13.4.13.1.
        if (!$value instanceof stdClass) {
            throw new JsonLdError(ErrorCode::InvalidReverseValue);
        }

        // Step 13.4.13.2.
        $expandedValue = $this->expand($activeContext, '@reverse', $value, $baseUrl);

        foreach ($expandedValue instanceof stdClass ? get_object_vars($expandedValue) : [] as $property => $items) {
            if ($property === '@reverse') {
                // Step 13.4.13.3. A property reversed twice is a property.
                foreach ($items instanceof stdClass ? get_object_vars($items) : [] as $forward => $forwardItems) {
                    $result->add((string) $forward, self::asList($forwardItems));
                }

                continue;
            }

            // Step 13.4.13.4.
            foreach (self::asList($items) as $item) {
                if (ObjectForms::isValueObject($item) || ObjectForms::isListObject($item)) {
                    throw new JsonLdError(ErrorCode::InvalidReversePropertyValue, (string) $property);
                }

                $result->addReverse((string) $property, $item);
            }
        }
    }

    /**
     * Steps 13.5 through 13.9: expands the value of a property
     *
     * How the value is read depends on the property's term definition. A type
     * of `@json` makes the value a JSON literal without expanding it. If the
     * term's container is a language map, or an index, type, or identifier map,
     * and the value is a map, the value is unfolded into its items. Anything
     * else is expanded as an element in its own right.
     *
     * @return stdClass | list<mixed> | null
     */
    private function expandPropertyValue(
        ActiveContext $activeContext,
        string $key,
        ?TermDefinition $definition,
        mixed $value,
        ?string $baseUrl,
    ): stdClass | array | null {
        // Step 13.6.
        if ($definition !== null && $definition->typeMapping === '@json') {
            return (object) ['@type' => '@json', '@value' => $value];
        }

        if ($definition !== null && $value instanceof stdClass) {
            // Step 13.7.
            if ($definition->hasContainer('@language')) {
                return $this->expandLanguageMap($activeContext, $definition, get_object_vars($value));
            }

            // Step 13.8.
            if (
                $definition->hasContainer('@index')
                || $definition->hasContainer('@type')
                || $definition->hasContainer('@id')
            ) {
                return $this->expandIndexMap($activeContext, $key, $definition, get_object_vars($value), $baseUrl);
            }
        }

        // Step 13.9.
        return $this->expand($activeContext, $key, $value, $baseUrl);
    }

    /**
     * Step 13.7: expands a language map
     *
     * Each key is a language tag, and each value is a string or a list of
     * strings in that language. Every string becomes a value object with that
     * tag, in lowercase. Its base direction is the one in the property's
     * definition, or the context's when the definition has no `@direction`
     * entry. A definition whose `@direction` is null means no direction. The
     * key `@none`, or an alias of it, gives a value object with no language.
     * A null item is skipped.
     *
     * @param array<mixed> $languageMap
     *
     * @return list<stdClass>
     */
    private function expandLanguageMap(
        ActiveContext $activeContext,
        TermDefinition $definition,
        array $languageMap,
    ): array {
        // Step 13.7.1.
        $expandedValue = [];

        // Steps 13.7.2 and 13.7.3.
        $direction = $definition->hasDirectionMapping
            ? $definition->directionMapping
            : $activeContext->defaultBaseDirection;

        // Step 13.7.4.
        ksort($languageMap, SORT_STRING);

        foreach ($languageMap as $language => $languageValue) {
            $language = (string) $language;

            // Step 13.7.4.1.
            foreach (self::asList($languageValue) as $item) {
                // Step 13.7.4.2.1.
                if ($item === null) {
                    continue;
                }

                // Step 13.7.4.2.2.
                if (!is_string($item)) {
                    throw new JsonLdError(ErrorCode::InvalidLanguageMapValue, $language);
                }

                // Step 13.7.4.2.3.
                $valueObject = ['@value' => $item];

                // Step 13.7.4.2.4. The tag is lowercased, which the
                // specification allows.
                if (!self::expandsTo($activeContext, $language, '@none')) {
                    $valueObject['@language'] = strtolower($language);
                }

                // Step 13.7.4.2.5.
                if ($direction !== null) {
                    $valueObject['@direction'] = $direction;
                }

                // Step 13.7.4.2.6. Entries are in code point order throughout
                // the output.
                ksort($valueObject, SORT_STRING);
                $expandedValue[] = (object) $valueObject;
            }
        }

        return $expandedValue;
    }

    /**
     * Step 13.8: expands an index map, a type map, or an identifier map
     *
     * Each key of the map says something about the items under it, and this
     * step writes that into each item. In an index map the key becomes the
     * item's `@index`, unless the item has one. If the term names a property
     * with `@index`, the key becomes a value of that property instead. In a
     * type map the key is added to the item's `@type`. In an identifier map the
     * key becomes the item's `@id`, unless the item has one. The key `@none`,
     * or an alias of it, adds nothing.
     *
     * @param array<mixed> $indexMap
     *
     * @return list<mixed>
     */
    private function expandIndexMap(
        ActiveContext $activeContext,
        string $key,
        TermDefinition $definition,
        array $indexMap,
        ?string $baseUrl,
    ): array {
        $isGraph = $definition->hasContainer('@graph');
        $isIndex = $definition->hasContainer('@index');
        $isId = $definition->hasContainer('@id');
        $isType = $definition->hasContainer('@type');

        // Step 13.8.1.
        $expandedValue = [];

        // Step 13.8.2.
        $indexKey = $definition->indexMapping ?? '@index';

        // Step 13.8.3.
        ksort($indexMap, SORT_STRING);

        foreach ($indexMap as $index => $indexValue) {
            $index = (string) $index;

            // Steps 13.8.3.1 and 13.8.3.3. The items of an identifier map or a
            // type map are node objects of their own, so the type-scoped
            // context of the node that holds the map does not apply to them.
            // They start from the context that was in effect before it. The
            // items of any other map start from the active context.
            $mapContext = $isId || $isType ? $activeContext->previousContext ?? $activeContext : $activeContext;

            // Step 13.8.3.2. The key of a type map names a type, and that type
            // may have a scoped context. The specification does not say whether
            // it propagates. This code passes `false` for propagate, as the
            // reference processors do, so the scoped context applies to the
            // item and not to node objects nested inside it. That is how
            // `@type` behaves, and a type map is another way to write `@type`.
            $indexDefinition = $isType ? $mapContext->termDefinition($index) : null;

            if ($indexDefinition !== null && $indexDefinition->hasContext) {
                $mapContext = $this->contextProcessor->process(
                    $mapContext,
                    $indexDefinition->context,
                    $indexDefinition->baseUrl,
                    propagate: false,
                );
            }

            // Step 13.8.3.4.
            $expandedIndex = IriExpander::expand($activeContext, $index, vocab: true);

            // Steps 13.8.3.5 and 13.8.3.6.
            $items = $this->expandArray($mapContext, $key, self::asList($indexValue), $baseUrl, true);

            // Step 13.8.3.7.
            foreach ($items as $item) {
                // Step 13.8.3.7.1.
                if ($isGraph && !ObjectForms::isGraphObject($item)) {
                    $item = (object) ['@graph' => [$item]];
                }

                if (!$item instanceof stdClass || $expandedIndex === '@none') {
                    $expandedValue[] = $item;

                    continue;
                }

                $entries = get_object_vars($item);

                if ($isIndex && $indexKey !== '@index') {
                    // Step 13.8.3.7.2. A term may name a property with
                    // `@index`, and each key of the map then becomes a value of
                    // that property in the item. If the property expands to
                    // null because a later definition set it to null, the key
                    // cannot be recorded. Lenient mode leaves it out of the
                    // item. Strict mode raises `DataLoss`.
                    $expandedIndexKey = IriExpander::expand($activeContext, $indexKey, vocab: true);

                    if ($expandedIndexKey === null) {
                        $this->dropNullExpansion($indexKey);
                    } else {
                        $item->{$expandedIndexKey} = [
                            $this->expandValue($activeContext, $indexKey, $index),
                            ...self::asList($entries[$expandedIndexKey] ?? []),
                        ];

                        if (ObjectForms::isValueObject($item)) {
                            throw new JsonLdError(ErrorCode::InvalidValueObject, $key);
                        }
                    }
                } elseif ($isIndex && !array_key_exists('@index', $entries)) {
                    // Step 13.8.3.7.3.
                    $item->{'@index'} = $index;
                } elseif ($isId && !array_key_exists('@id', $entries)) {
                    // Step 13.8.3.7.4. The key becomes the item's identifier.
                    // A key that IRI expansion turns into null stays null.
                    $expandedId = IriExpander::expand($activeContext, $index, documentRelative: true);

                    if ($expandedId === null) {
                        $this->dropNullExpansion($index);
                    }

                    $item->{'@id'} = $expandedId;
                } elseif ($isType) {
                    // Step 13.8.3.7.5. The expanded key becomes a type of the
                    // item. A key that IRI expansion turns into null stays
                    // null.
                    if ($expandedIndex === null) {
                        $this->dropNullExpansion($index);
                    }

                    $item->{'@type'} = [$expandedIndex, ...self::asList($entries['@type'] ?? [])];
                }

                // Step 13.8.3.7.6. Entries are in code point order throughout
                // the output.
                $entries = get_object_vars($item);
                ksort($entries, SORT_STRING);
                $expandedValue[] = (object) $entries;
            }
        }

        return $expandedValue;
    }

    /**
     * Steps 4.3 and 13.8.3.7.2: expands a scalar with Value Expansion
     *
     * Steps 1 and 2 of Value Expansion turn a string into `{"@id": ...}` when
     * the property's type mapping is `@id` or `@vocab`. If IRI expansion
     * returns null for the string, the result is `{"@id": null}` and the string
     * is lost. Value Expansion does not know whether strict mode is on, so this
     * method checks for that result and raises `DataLoss` in strict mode.
     */
    private function expandValue(ActiveContext $activeContext, string $activeProperty, mixed $value): stdClass
    {
        $expandedValue = ValueExpander::expand($activeContext, $activeProperty, $value);
        $entries = get_object_vars($expandedValue);

        if (is_string($value) && array_key_exists('@id', $entries) && $entries['@id'] === null) {
            $this->dropNullExpansion($value);
        }

        return $expandedValue;
    }

    /**
     * Steps 15 through 20: checks the result and gives it its final shape
     *
     * A value object is checked and, unless its type is `@json`, dropped if its
     * value is null or an empty array. Otherwise one of two things happens: an
     * `@type` that is not a list becomes one, or a set or list object is
     * checked and a set object is replaced by its contents. After that, a map
     * with only `@language` is dropped. At the top level or inside `@graph`, a
     * map that says nothing about a node is dropped as free-floating. In strict
     * mode, each drop raises `DataLoss`.
     *
     * @param array<string, mixed> $result
     *
     * @return stdClass | list<mixed> | null
     */
    private function finish(array $result, ?string $activeProperty): stdClass | array | null
    {
        if (array_key_exists('@value', $result)) {
            // Step 15.1.
            if (
                array_diff(array_keys($result), self::VALUE_OBJECT_KEYWORDS) !== []
                || (
                    array_key_exists('@type', $result)
                    && (array_key_exists('@language', $result) || array_key_exists('@direction', $result))
                )
            ) {
                throw new JsonLdError(ErrorCode::InvalidValueObject);
            }

            $type = $result['@type'] ?? null;

            // Step 15.2.
            if ($type !== '@json') {
                // Step 15.3.
                if ($result['@value'] === null || $result['@value'] === []) {
                    $this->drop(DataLossCondition::NullValue, (object) $result);

                    return null;
                }

                // Step 15.4.
                if (!is_string($result['@value']) && array_key_exists('@language', $result)) {
                    throw new JsonLdError(ErrorCode::InvalidLanguageTaggedValue);
                }

                // Step 15.5. The `@type` of a value object must be an IRI. This
                // code accepts what the RDF model accepts: an absolute IRI with
                // no character that N-Quads forbids, such as a space.
                // Otherwise, it raises `invalid typed value`, as W3C test
                // #t0123 expects.
                if (array_key_exists('@type', $result) && !(is_string($type) && Grammar::isWellFormedIri($type))) {
                    throw new JsonLdError(ErrorCode::InvalidTypedValue);
                }
            }
        } elseif (array_key_exists('@type', $result) && !is_array($result['@type'])) {
            // Step 16.
            $result['@type'] = [$result['@type']];
        } elseif (array_key_exists('@set', $result) || array_key_exists('@list', $result)) {
            // Step 17.1.
            if (count($result) > 2 || (count($result) === 2 && !array_key_exists('@index', $result))) {
                throw new JsonLdError(ErrorCode::InvalidSetOrListObject);
            }

            // Step 17.2.
            if (array_key_exists('@set', $result)) {
                return $result['@set'] === null ? null : self::asList($result['@set']);
            }
        }

        // Step 18.
        if (count($result) === 1 && array_key_exists('@language', $result)) {
            $this->drop(DataLossCondition::FreeFloatingValue, (object) $result);

            return null;
        }

        // Step 19.
        if ($activeProperty === null || $activeProperty === '@graph') {
            // Step 19.1. A map that has `@value` or `@list` is dropped whatever
            // else it has, as the reference processors drop it.
            if (array_key_exists('@value', $result) || array_key_exists('@list', $result)) {
                $this->drop(DataLossCondition::FreeFloatingValue, (object) $result);

                return null;
            }

            // Steps 19.1 and 19.2.
            if ($result === [] || (count($result) === 1 && array_key_exists('@id', $result))) {
                $this->drop(DataLossCondition::FreeFloatingNode, (object) $result);

                return null;
            }
        }

        // Step 20.
        return (object) $result;
    }

    /**
     * In strict mode, refuses to drop what the specification drops silently
     */
    private function drop(DataLossCondition $condition, mixed $dropped): void
    {
        if ($this->options->strict) {
            throw new DataLoss($condition, is_string($dropped) ? $dropped : JsonCanonicalizer::canonicalize($dropped));
        }
    }

    /**
     * In strict mode, refuses to drop a string that IRI expansion turns into
     * null
     *
     * A string with the form of a keyword is reserved for a keyword of a
     * later version of the specification; any other is a term the active
     * context defines as null.
     */
    private function dropNullExpansion(string $value): void
    {
        $this->drop(
            Keywords::hasKeywordForm($value)
                ? DataLossCondition::ReservedTerm
                : DataLossCondition::UndefinedProperty,
            $value,
        );
    }

    /**
     * Step 14.2.1: whether any key of a map is `@value` or an alias of it
     *
     * A map nested under `@nest` must not have such a key.
     *
     * @param array<mixed> $entries
     */
    private function hasValueEntry(ActiveContext $activeContext, array $entries): bool
    {
        foreach (array_keys($entries) as $key) {
            if (self::expandsTo($activeContext, (string) $key, '@value')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if the key is the keyword or an alias of it
     *
     * IRI expansion resolves a keyword and an alias of a keyword in its first
     * steps, before its `vocab` and `documentRelative` flags come into play.
     * The flags cannot change the answer, so this method passes neither.
     */
    private static function expandsTo(ActiveContext $activeContext, string $key, string $keyword): bool
    {
        return IriExpander::expand($activeContext, $key) === $keyword;
    }

    /**
     * Returns a list as it is, and anything else as a list of one
     *
     * @return list<mixed>
     */
    private static function asList(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [$value];
    }
}
