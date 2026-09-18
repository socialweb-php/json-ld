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

/**
 * The error codes of JSON-LD 1.1 Processing Algorithms and API section 10.2
 */
enum ErrorCode: string
{
    case CollidingKeywords = 'colliding keywords';
    case ConflictingIndexes = 'conflicting indexes';
    case ContextOverflow = 'context overflow';
    case CyclicIriMapping = 'cyclic IRI mapping';
    case InvalidBaseDirection = 'invalid base direction';
    case InvalidBaseIri = 'invalid base IRI';
    case InvalidContainerMapping = 'invalid container mapping';
    case InvalidContextEntry = 'invalid context entry';
    case InvalidContextNullification = 'invalid context nullification';
    case InvalidDefaultLanguage = 'invalid default language';
    case InvalidIdValue = 'invalid @id value';
    case InvalidImportValue = 'invalid @import value';
    case InvalidIncludedValue = 'invalid @included value';
    case InvalidIndexValue = 'invalid @index value';
    case InvalidIriMapping = 'invalid IRI mapping';
    case InvalidJsonLiteral = 'invalid JSON literal';
    case InvalidKeywordAlias = 'invalid keyword alias';
    case InvalidLanguageMapValue = 'invalid language map value';
    case InvalidLanguageMapping = 'invalid language mapping';
    case InvalidLanguageTaggedString = 'invalid language-tagged string';
    case InvalidLanguageTaggedValue = 'invalid language-tagged value';
    case InvalidLocalContext = 'invalid local context';
    case InvalidNestValue = 'invalid @nest value';
    case InvalidPrefixValue = 'invalid @prefix value';
    case InvalidPropagateValue = 'invalid @propagate value';
    case InvalidProtectedValue = 'invalid @protected value';
    case InvalidRemoteContext = 'invalid remote context';
    case InvalidReverseProperty = 'invalid reverse property';
    case InvalidReversePropertyMap = 'invalid reverse property map';
    case InvalidReversePropertyValue = 'invalid reverse property value';
    case InvalidReverseValue = 'invalid @reverse value';
    case InvalidScopedContext = 'invalid scoped context';
    case InvalidScriptElement = 'invalid script element';
    case InvalidSetOrListObject = 'invalid set or list object';
    case InvalidTermDefinition = 'invalid term definition';
    case InvalidTypeMapping = 'invalid type mapping';
    case InvalidTypeValue = 'invalid type value';
    case InvalidTypedValue = 'invalid typed value';
    case InvalidValueObject = 'invalid value object';
    case InvalidValueObjectValue = 'invalid value object value';
    case InvalidVersionValue = 'invalid @version value';
    case InvalidVocabMapping = 'invalid vocab mapping';
    case IriConfusedWithPrefix = 'IRI confused with prefix';
    case KeywordRedefinition = 'keyword redefinition';
    case LoadingDocumentFailed = 'loading document failed';
    case LoadingRemoteContextFailed = 'loading remote context failed';
    case MultipleContextLinkHeaders = 'multiple context link headers';
    case ProcessingModeConflict = 'processing mode conflict';
    case ProtectedTermRedefinition = 'protected term redefinition';
}
