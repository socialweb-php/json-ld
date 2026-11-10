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

namespace SocialWeb\JsonLd\Exception;

/**
 * The error codes of JSON-LD 1.1 Processing Algorithms and API section 9.6.2,
 * each backed by the specification's own string
 *
 * The enum has every code the specification defines, so that a case exists
 * for each one a caller may know from another processor. Four belong to
 * features this package does not implement, and it never raises them; their
 * docblocks say so.
 *
 * @link https://www.w3.org/TR/json-ld11-api/#jsonlderrorcode JSON-LD 1.1 API, JsonLdErrorCode
 */
enum JsonLdErrorCode: string
{
    /**
     * Two entries of one map expand to the same keyword, as when a keyword
     * and an alias of it are both used
     */
    case CollidingKeywords = 'colliding keywords';

    /**
     * Two parts of the document give one node different `@index` values
     */
    case ConflictingIndexes = 'conflicting indexes';

    /**
     * A chain of contexts that reference one another by URL is longer than
     * the `maxDepth` limit allows
     */
    case ContextOverflow = 'context overflow';

    /**
     * A term's definition depends on the term itself, directly or through
     * other terms
     */
    case CyclicIriMapping = 'cyclic IRI mapping';

    /**
     * An `@direction` value, in a context, a term definition, or a value
     * object, is not `"ltr"`, `"rtl"`, or null
     */
    case InvalidBaseDirection = 'invalid base direction';

    /**
     * An `@base` entry of a context is not an IRI, a relative reference that
     * the current base can resolve, or null
     */
    case InvalidBaseIri = 'invalid base IRI';

    /**
     * An `@container` entry of a term definition is not one of the values, or
     * the combinations of values, that the specification allows
     */
    case InvalidContainerMapping = 'invalid container mapping';

    /**
     * A context has an entry that the processing mode does not allow, such
     * as `@import`, `@direction`, or `@propagate` in `json-ld-1.0` mode, or
     * an imported context that has an `@import` of its own
     */
    case InvalidContextEntry = 'invalid context entry';

    /**
     * A null context would remove protected term definitions
     */
    case InvalidContextNullification = 'invalid context nullification';

    /**
     * An `@language` entry of a context is not a string or null
     */
    case InvalidDefaultLanguage = 'invalid default language';

    /**
     * An `@id` entry of a node object is not a string
     */
    case InvalidIdValue = 'invalid @id value';

    /**
     * An `@import` entry of a context is not a string
     */
    case InvalidImportValue = 'invalid @import value';

    /**
     * An `@included` entry holds something other than node objects
     */
    case InvalidIncludedValue = 'invalid @included value';

    /**
     * An `@index` entry is not a string
     */
    case InvalidIndexValue = 'invalid @index value';

    /**
     * A term definition has an IRI mapping that is missing, is not a string,
     * or does not expand to an IRI, a blank node identifier, or a keyword
     */
    case InvalidIriMapping = 'invalid IRI mapping';

    /**
     * A JSON literal is not valid
     *
     * This package never raises it: a JSON literal reaches the processor
     * already parsed, so there is no JSON text left to be invalid.
     */
    case InvalidJsonLiteral = 'invalid JSON literal';

    /**
     * A term is defined as an alias of `@context`
     */
    case InvalidKeywordAlias = 'invalid keyword alias';

    /**
     * A value of a language map is not a string, null, or an array of them
     */
    case InvalidLanguageMapValue = 'invalid language map value';

    /**
     * An `@language` entry of a term definition is not a string or null
     */
    case InvalidLanguageMapping = 'invalid language mapping';

    /**
     * An `@language` entry of a value object is not a string
     */
    case InvalidLanguageTaggedString = 'invalid language-tagged string';

    /**
     * A value object has `@language` and an `@value` that is not a string
     */
    case InvalidLanguageTaggedValue = 'invalid language-tagged value';

    /**
     * A context is not a map, a string, or null
     */
    case InvalidLocalContext = 'invalid local context';

    /**
     * An `@nest` entry of a term definition is not a string or names a
     * keyword other than `@nest`, or the value of a nesting property is not
     * a map or has an `@value` entry
     */
    case InvalidNestValue = 'invalid @nest value';

    /**
     * An `@prefix` entry of a term definition is not a boolean
     */
    case InvalidPrefixValue = 'invalid @prefix value';

    /**
     * An `@propagate` entry of a context is not a boolean
     */
    case InvalidPropagateValue = 'invalid @propagate value';

    /**
     * An `@protected` entry of a context or a term definition is not a
     * boolean
     */
    case InvalidProtectedValue = 'invalid @protected value';

    /**
     * A document that a context URL names is not a map with an `@context`
     * entry, or, for a context that `@import` names, its `@context` is not a
     * map
     */
    case InvalidRemoteContext = 'invalid remote context';

    /**
     * A term definition with `@reverse` also has `@id` or `@nest`, or has a
     * container other than `@set` or `@index`
     */
    case InvalidReverseProperty = 'invalid reverse property';

    /**
     * A map under `@reverse` has an entry that expands to a keyword
     */
    case InvalidReversePropertyMap = 'invalid reverse property map';

    /**
     * A value of a reverse property is a value object or a list object,
     * where only a node object is allowed
     */
    case InvalidReversePropertyValue = 'invalid reverse property value';

    /**
     * An `@reverse` entry of a node object is not a map
     */
    case InvalidReverseValue = 'invalid @reverse value';

    /**
     * The context in a term definition's `@context` entry is not valid; the
     * error it raised is the previous exception
     */
    case InvalidScopedContext = 'invalid scoped context';

    /**
     * A script element in HTML input does not have the type JSON-LD needs
     *
     * This package never raises it, since it does not read JSON-LD from
     * HTML.
     */
    case InvalidScriptElement = 'invalid script element';

    /**
     * A set object or a list object has an entry other than `@set` or
     * `@list` and `@index`
     */
    case InvalidSetOrListObject = 'invalid set or list object';

    /**
     * A term or its definition breaks a rule that has no code of its own:
     * the term is the empty string, the definition is not a string, a map,
     * or null, or it has an entry that the specification or the processing
     * mode does not allow
     */
    case InvalidTermDefinition = 'invalid term definition';

    /**
     * An `@type` entry of a term definition is not a string, or does not
     * expand to an IRI or to one of the keywords allowed there
     */
    case InvalidTypeMapping = 'invalid type mapping';

    /**
     * An `@type` entry of a node object is not a string or an array of
     * strings
     */
    case InvalidTypeValue = 'invalid type value';

    /**
     * The `@type` of a value object is not an IRI
     */
    case InvalidTypedValue = 'invalid typed value';

    /**
     * A value object has an entry it may not have, or has `@type` together
     * with `@language` or `@direction`, or an index map with a property as
     * its index would add that property to a value object
     */
    case InvalidValueObject = 'invalid value object';

    /**
     * The `@value` of a value object is a map or an array and its `@type`
     * is not `@json`, or its `@type` is `@json` in `json-ld-1.0` mode
     */
    case InvalidValueObjectValue = 'invalid value object value';

    /**
     * An `@version` entry of a context is not the number 1.1
     */
    case InvalidVersionValue = 'invalid @version value';

    /**
     * An `@vocab` entry of a context is not a string or null, or does not
     * expand to an IRI or a blank node identifier
     */
    case InvalidVocabMapping = 'invalid vocab mapping';

    /**
     * Compacting an IRI would give a result that reads as a compact IRI
     *
     * This package never raises it, since it does not compact.
     */
    case IriConfusedWithPrefix = 'IRI confused with prefix';

    /**
     * A context defines a term that is a keyword, other than the one form
     * of `@type` the specification allows
     */
    case KeywordRedefinition = 'keyword redefinition';

    /**
     * The document could not be read as JSON: its text does not parse, or a
     * value in it is one that JSON text cannot express, such as a number
     * that is not finite, a string or a member name that is not valid UTF-8,
     * or a member name that starts with a NUL byte. It is also raised for a
     * relative context URL when there is no base to resolve it against.
     */
    case LoadingDocumentFailed = 'loading document failed';

    /**
     * The document loader could not supply the document for a context URL
     */
    case LoadingRemoteContextFailed = 'loading remote context failed';

    /**
     * An HTTP response has more than one link header for a JSON-LD context
     *
     * This package never raises it, since it does not fetch documents.
     */
    case MultipleContextLinkHeaders = 'multiple context link headers';

    /**
     * A context has `@version` 1.1 and the processing mode is `json-ld-1.0`
     */
    case ProcessingModeConflict = 'processing mode conflict';

    /**
     * A context redefines a protected term in a way that changes it
     */
    case ProtectedTermRedefinition = 'protected term redefinition';
}
