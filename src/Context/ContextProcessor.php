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

use Closure;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\DocumentReader;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\JsonLd\Grammar;
use SocialWeb\JsonLd\IriResolver;
use SocialWeb\JsonLd\Keywords;
use SocialWeb\JsonLd\Limits;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\ProcessingMode;
use Throwable;
use stdClass;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function get_object_vars;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function ksort;
use function sort;
use function str_contains;
use function str_starts_with;
use function strpos;
use function substr;

use const PHP_INT_MAX;
use const SORT_STRING;

/**
 * The Context Processing Algorithm and Create Term Definition from JSON-LD 1.1
 * Processing Algorithms and API sections 4.1 and 4.2
 *
 * The step numbers in the comments are the specification's. Where a comment
 * says the code follows "the reference processors" and not the text, it means
 * jsonld.js and the Ruby json-ld gem, the two implementations this one was
 * checked against.
 *
 * Contexts arrive in the library's internal form: JSON objects as `stdClass`,
 * JSON arrays as lists. The entries of a context definition are visited in code
 * point order, so that the first error reported for a context with several
 * errors does not depend on how the context was written.
 *
 * @internal
 */
final class ContextProcessor
{
    /**
     * The entries of a context definition that are not term definitions
     */
    private const array CONTEXT_KEYWORDS = [
        '@base',
        '@direction',
        '@import',
        '@language',
        '@propagate',
        '@protected',
        '@version',
        '@vocab',
    ];

    /**
     * The entries an expanded term definition may have
     */
    private const array TERM_DEFINITION_KEYWORDS = [
        '@container',
        '@context',
        '@direction',
        '@id',
        '@index',
        '@language',
        '@nest',
        '@prefix',
        '@protected',
        '@reverse',
        '@type',
    ];

    /**
     * The keywords a container mapping may hold
     */
    private const array CONTAINER_KEYWORDS = ['@graph', '@id', '@index', '@language', '@list', '@set', '@type'];

    /**
     * The `gen-delims` characters of RFC 3986; a simple term whose IRI ends
     * in one can be used as a prefix
     */
    private const string GEN_DELIMS = ':/?#[]@';

    /**
     * Remote contexts already dereferenced, by URL: the document URL and the
     * document in the internal form
     *
     * @var array<string, array{string, mixed}>
     */
    private array $dereferenced = [];

    public function __construct(private readonly Options $options)
    {
    }

    /**
     * Returns the active context that results from applying a local context
     *
     * @param mixed $localContext The value of an `@context` entry, in the
     *     internal form
     * @param string | null $baseUrl The base for resolving context URLs: the
     *     URL of the document that holds the local context, if it has one
     * @param list<string> $remoteContexts The remote contexts being processed
     *     when this one was reached
     * @param bool $overrideProtected Whether protected terms may be redefined
     * @param bool $propagate Whether the context stays in effect inside node
     *     objects nested below the one it applies to
     * @param bool $validateScopedContext `false` to stop at a remote context
     *     that is already being processed, while validating a scoped context
     *
     * @throws JsonLdError if the local context breaks a rule of the
     *     specification or a remote context cannot be loaded
     * @throws DataLoss in strict mode, if a term would be ignored because it
     *     or its IRI has the form of a keyword
     * @throws LimitExceeded if a chain of terms that depend on one another is
     *     longer than the depth limit
     */
    public function process(
        ActiveContext $activeContext,
        mixed $localContext,
        ?string $baseUrl,
        array $remoteContexts = [],
        bool $overrideProtected = false,
        bool $propagate = true,
        bool $validateScopedContext = true,
    ): ActiveContext {
        // Step 1.
        $result = $activeContext;

        // Step 2. A value that is not a boolean is an error in step 5.11.
        if ($localContext instanceof stdClass && is_bool($localContext->{'@propagate'} ?? null)) {
            $propagate = $localContext->{'@propagate'};
        }

        // Step 3.
        if (!$propagate && $result->previousContext === null) {
            $result = $result->withPreviousContext($activeContext);
        }

        // Step 4.
        if (!is_array($localContext)) {
            $localContext = [$localContext];
        }

        // Step 5.
        foreach ($localContext as $context) {
            // Step 5.1.
            if ($context === null) {
                // Step 5.1.1. The check is made against the result so far, so
                // that a null after a protected context in one array is caught.
                if (!$overrideProtected && $result->hasProtectedTermDefinitions()) {
                    throw new JsonLdError(ErrorCode::InvalidContextNullification);
                }

                // Step 5.1.2.
                $previous = $result;
                $result = ActiveContext::initial($activeContext->originalBaseUrl);

                if (!$propagate) {
                    $result = $result->withPreviousContext($previous);
                }

                continue;
            }

            // Step 5.2.
            if (is_string($context)) {
                $result = $this->processRemoteContext(
                    $result,
                    $context,
                    $baseUrl,
                    $remoteContexts,
                    $overrideProtected,
                    $validateScopedContext,
                );

                continue;
            }

            // Step 5.3.
            if (!$context instanceof stdClass) {
                throw new JsonLdError(ErrorCode::InvalidLocalContext);
            }

            // Step 5.4.
            $result = $this->processContextDefinition(
                $result,
                get_object_vars($context),
                $baseUrl,
                $remoteContexts,
                $overrideProtected,
                $validateScopedContext,
            );
        }

        // Step 6.
        return $result;
    }

    /**
     * Step 5.2: a context given by reference
     *
     * @param list<string> $remoteContexts
     */
    private function processRemoteContext(
        ActiveContext $result,
        string $context,
        ?string $baseUrl,
        array $remoteContexts,
        bool $overrideProtected,
        bool $validateScopedContext,
    ): ActiveContext {
        // Step 5.2.1.
        $context = $this->resolveContextUrl($context, $baseUrl);

        // Step 5.2.2.
        if (!$validateScopedContext && in_array($context, $remoteContexts, true)) {
            return $result;
        }

        // Step 5.2.3.
        if (count($remoteContexts) >= $this->options->limits->maxDepth) {
            throw new JsonLdError(ErrorCode::ContextOverflow, $context);
        }

        $remoteContexts[] = $context;

        // Steps 5.2.4 and 5.2.5.
        [$documentUrl, $document] = $this->dereference($context);

        if (!$document instanceof stdClass || !array_key_exists('@context', get_object_vars($document))) {
            throw new JsonLdError(ErrorCode::InvalidRemoteContext, $context);
        }

        // Step 5.2.6. The specification does not pass override protected
        // here; it is passed so that a scoped context given by reference may
        // redefine protected terms as one written inline may.
        return $this->process(
            $result,
            $document->{'@context'},
            $documentUrl,
            $remoteContexts,
            $overrideProtected,
            validateScopedContext: $validateScopedContext,
        );
    }

    /**
     * Steps 5.5 through 5.13: a context definition
     *
     * @param array<mixed> $context The entries of the context definition
     * @param list<string> $remoteContexts
     */
    private function processContextDefinition(
        ActiveContext $result,
        array $context,
        ?string $baseUrl,
        array $remoteContexts,
        bool $overrideProtected,
        bool $validateScopedContext,
    ): ActiveContext {
        $isJsonLd10 = $this->options->processingMode === ProcessingMode::JsonLd10;

        // Step 5.5.
        if (array_key_exists('@version', $context)) {
            if ($context['@version'] !== 1.1) {
                throw new JsonLdError(ErrorCode::InvalidVersionValue);
            }

            if ($isJsonLd10) {
                throw new JsonLdError(ErrorCode::ProcessingModeConflict);
            }
        }

        // Step 5.6.
        if (array_key_exists('@import', $context)) {
            if ($isJsonLd10) {
                throw new JsonLdError(ErrorCode::InvalidContextEntry, '@import');
            }

            if (!is_string($context['@import'])) {
                throw new JsonLdError(ErrorCode::InvalidImportValue);
            }

            $import = $this->resolveContextUrl($context['@import'], $baseUrl);
            $importContext = $this->dereference($import)[1];

            if ($importContext instanceof stdClass) {
                $importContext = $importContext->{'@context'} ?? null;
            }

            if (!$importContext instanceof stdClass) {
                throw new JsonLdError(ErrorCode::InvalidRemoteContext, $import);
            }

            $importContext = get_object_vars($importContext);

            if (array_key_exists('@import', $importContext)) {
                throw new JsonLdError(ErrorCode::InvalidContextEntry, '@import');
            }

            // Entries of the importing context replace those of the imported.
            $context += $importContext;
        }

        // Step 5.7.
        if (array_key_exists('@base', $context) && count($remoteContexts) === 0) {
            $result = $result->withBaseIri($this->baseIri($context['@base'], $result->baseIri));
        }

        // Step 5.8.
        if (array_key_exists('@vocab', $context)) {
            $result = $result->withVocabularyMapping($this->vocabularyMapping($context['@vocab'], $result));
        }

        // Step 5.9.
        if (array_key_exists('@language', $context)) {
            $value = $context['@language'];

            if ($value !== null && !is_string($value)) {
                throw new JsonLdError(ErrorCode::InvalidDefaultLanguage);
            }

            $result = $result->withDefaultLanguage($value);
        }

        // Step 5.10.
        if (array_key_exists('@direction', $context)) {
            if ($isJsonLd10) {
                throw new JsonLdError(ErrorCode::InvalidContextEntry, '@direction');
            }

            $value = $context['@direction'];

            if ($value !== null && $value !== 'ltr' && $value !== 'rtl') {
                throw new JsonLdError(ErrorCode::InvalidBaseDirection);
            }

            $result = $result->withDefaultBaseDirection($value);
        }

        // Step 5.11.
        if (array_key_exists('@propagate', $context)) {
            if ($isJsonLd10) {
                throw new JsonLdError(ErrorCode::InvalidContextEntry, '@propagate');
            }

            if (!is_bool($context['@propagate'])) {
                throw new JsonLdError(ErrorCode::InvalidPropagateValue);
            }
        }

        // Step 5.12.
        $defined = [];

        // Step 5.13. The specification does not say what a protected flag
        // that is not a boolean means; it is refused, so that a mistake in a
        // context cannot leave its terms unprotected.
        $protected = array_key_exists('@protected', $context) ? $context['@protected'] : false;

        if (!is_bool($protected)) {
            throw new JsonLdError(ErrorCode::InvalidProtectedValue);
        }

        // The terms are defined in a builder, which changes in place, and
        // the result is built from it once all of them are defined.
        $builder = new ActiveContextBuilder($result);
        ksort($context, SORT_STRING);

        foreach (array_keys($context) as $term) {
            $term = (string) $term;

            if (in_array($term, self::CONTEXT_KEYWORDS, true)) {
                continue;
            }

            $this->createTermDefinition(
                $builder,
                $context,
                $term,
                $defined,
                $baseUrl,
                $protected,
                $overrideProtected,
                $remoteContexts,
                $validateScopedContext,
            );
        }

        return $builder->build();
    }

    /**
     * Step 5.7: the base IRI that an `@base` entry sets
     */
    private function baseIri(mixed $value, ?string $currentBaseIri): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && Grammar::isAbsoluteIri($value)) {
            return $value;
        }

        if (is_string($value) && $currentBaseIri !== null) {
            return IriResolver::resolve($value, $currentBaseIri);
        }

        throw new JsonLdError(ErrorCode::InvalidBaseIri);
    }

    /**
     * Step 5.8: the vocabulary mapping that an `@vocab` entry sets
     *
     * JSON-LD 1.1 allows a vocabulary mapping that is relative to the
     * previous vocabulary mapping or to the base IRI, so the value is
     * expanded with both the vocab and the document-relative flags, as the
     * reference processors do.
     */
    private function vocabularyMapping(mixed $value, ActiveContext $result): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new JsonLdError(ErrorCode::InvalidVocabMapping);
        }

        $isIri = Grammar::isAbsoluteIri($value) || str_starts_with($value, '_:');

        if (!$isIri && $this->options->processingMode === ProcessingMode::JsonLd10) {
            throw new JsonLdError(ErrorCode::InvalidVocabMapping, $value);
        }

        return IriExpander::expand($result, $value, documentRelative: true, vocab: true)
            ?? throw new JsonLdError(ErrorCode::InvalidVocabMapping, $value);
    }

    /**
     * Create Term Definition, section 4.2
     *
     * The algorithm changes the active context and the map of defined terms,
     * and its callers go on to use what it changed. The active context is a
     * builder, which changes in place, and the map is passed by reference.
     *
     * A term whose IRI is a compact IRI depends on the term that is its
     * prefix, and that term may depend on another. The algorithm calls itself
     * once for each, so the depth limit bounds the length of such a chain;
     * the specification has no rule for this.
     *
     * @param array<mixed> $localContext The entries of the context definition
     * @param array<bool> $defined Terms by name: true once defined, false
     *     while being defined
     * @param list<string> $remoteContexts
     * @param int $depth The number of terms being defined at once, this one
     *     included
     */
    private function createTermDefinition(
        ActiveContextBuilder $activeContext,
        array $localContext,
        string $term,
        array &$defined,
        ?string $baseUrl,
        bool $protected,
        bool $overrideProtected,
        array $remoteContexts,
        bool $validateScopedContext,
        int $depth = 1,
    ): void {
        $isJsonLd10 = $this->options->processingMode === ProcessingMode::JsonLd10;

        // Step 1.
        if (array_key_exists($term, $defined)) {
            if ($defined[$term]) {
                return;
            }

            throw new JsonLdError(ErrorCode::CyclicIriMapping, $term);
        }

        if ($depth > $this->options->limits->maxDepth) {
            throw new LimitExceeded('maxDepth', $this->options->limits->maxDepth);
        }

        // Step 2.
        if ($term === '') {
            throw new JsonLdError(ErrorCode::InvalidTermDefinition, 'the empty string');
        }

        $defined[$term] = false;

        // Step 3.
        $value = $localContext[$term] ?? null;

        if ($term === '@type') {
            // Step 4.
            $this->checkTypeRedefinition($value, $isJsonLd10);
        } elseif (Keywords::isKeyword($term)) {
            // Step 5.
            throw new JsonLdError(ErrorCode::KeywordRedefinition, $term);
        } elseif (Keywords::hasKeywordForm($term)) {
            $this->ignoreReservedTerm($term);

            return;
        }

        // Step 6.
        $previousDefinition = $activeContext->termDefinition($term);
        $activeContext->remove($term);

        // Steps 7 through 9.
        $simpleTerm = is_string($value);

        if ($value === null || is_string($value)) {
            $value = ['@id' => $value];
        } elseif ($value instanceof stdClass) {
            $value = get_object_vars($value);
        } else {
            throw new JsonLdError(ErrorCode::InvalidTermDefinition, $term);
        }

        // A closure for IRI expansion's steps 3 and 6.3. Those steps skip a
        // term that is already defined; step 1 of this algorithm does that.
        $define = function (string $dependency) use (
            $activeContext,
            $localContext,
            &$defined,
            $baseUrl,
            $protected,
            $overrideProtected,
            $remoteContexts,
            $validateScopedContext,
            $depth,
        ): void {
            if (array_key_exists($dependency, $localContext)) {
                $this->createTermDefinition(
                    $activeContext,
                    $localContext,
                    $dependency,
                    $defined,
                    $baseUrl,
                    $protected,
                    $overrideProtected,
                    $remoteContexts,
                    $validateScopedContext,
                    $depth + 1,
                );
            }
        };

        // Step 11.
        if (array_key_exists('@protected', $value)) {
            if (!is_bool($value['@protected'])) {
                throw new JsonLdError(ErrorCode::InvalidProtectedValue, $term);
            }

            if ($isJsonLd10) {
                throw new JsonLdError(ErrorCode::InvalidTermDefinition, $term);
            }

            $protected = $value['@protected'];
        }

        // Step 12.
        $typeMapping = null;

        if (array_key_exists('@type', $value)) {
            $typeMapping = $this->typeMapping($activeContext, $value['@type'], $term, $define, $isJsonLd10);
        }

        // The position of the first colon after the first character, counted
        // from the second character.
        $colon = strpos(substr($term, 1), ':');
        $prefix = false;
        $reverse = array_key_exists('@reverse', $value);

        if ($reverse) {
            // Step 13.
            $iriMapping = $this->reverseIriMapping($activeContext, $value, $term, $define);

            if ($iriMapping === null) {
                $this->restore($activeContext, $term, $previousDefinition);

                return;
            }

            // The specification ends the algorithm here, at step 13.7. That
            // would lose the `@context`, `@direction`, `@index`, `@language`,
            // and `@prefix` entries of a reverse property and skip the
            // checks of steps 26 and 27. This code carries on from step 19
            // instead, as the reference processors do.
        } elseif (array_key_exists('@id', $value) && $value['@id'] !== $term) {
            // Step 14.
            $iriMapping = null;

            if ($value['@id'] !== null) {
                if (!is_string($value['@id'])) {
                    throw new JsonLdError(ErrorCode::InvalidIriMapping, $term);
                }

                if (!Keywords::isKeyword($value['@id']) && Keywords::hasKeywordForm($value['@id'])) {
                    $this->ignoreReservedTerm($term);
                    $this->restore($activeContext, $term, $previousDefinition);

                    return;
                }

                $iriMapping = IriExpander::expand($activeContext, $value['@id'], vocab: true, define: $define);

                if ($iriMapping === null || !$this->isKeywordIriOrBlankNode($iriMapping)) {
                    throw new JsonLdError(ErrorCode::InvalidIriMapping, $term);
                }

                if ($iriMapping === '@context') {
                    throw new JsonLdError(ErrorCode::InvalidKeywordAlias, $term);
                }

                // Step 14.2.4. The colon may not be the first or last character.
                if (str_contains(substr($term, 1, -1), ':') || str_contains($term, '/')) {
                    $defined[$term] = true;

                    if (IriExpander::expand($activeContext, $term, vocab: true, define: $define) !== $iriMapping) {
                        throw new JsonLdError(ErrorCode::InvalidIriMapping, $term);
                    }
                }

                // Step 14.2.5.
                $prefix = !str_contains($term, ':')
                    && !str_contains($term, '/')
                    && $simpleTerm
                    && (
                        str_starts_with($iriMapping, '_:')
                        || (
                            Grammar::isAbsoluteIri($iriMapping)
                            && str_contains(self::GEN_DELIMS, substr($iriMapping, -1))
                        )
                    );
            }
        } elseif ($colon !== false) {
            // Step 15.
            $termPrefix = substr($term, 0, $colon + 1);
            $define($termPrefix);
            $prefixMapping = $activeContext->termDefinition($termPrefix)?->iriMapping;
            $iriMapping = $prefixMapping !== null ? $prefixMapping . substr($term, $colon + 2) : $term;
        } elseif (str_contains($term, '/')) {
            // Step 16. The term is expanded without the local context: it is
            // being defined, so looking it up there would report a cycle.
            $iriMapping = IriExpander::expand($activeContext, $term, vocab: true);

            if ($iriMapping === null || !Grammar::isAbsoluteIri($iriMapping)) {
                throw new JsonLdError(ErrorCode::InvalidIriMapping, $term);
            }
        } elseif ($term === '@type') {
            // Step 17.
            $iriMapping = '@type';
        } elseif ($activeContext->vocabularyMapping !== null) {
            // Step 18.
            $iriMapping = $activeContext->vocabularyMapping . $term;
        } else {
            throw new JsonLdError(ErrorCode::InvalidIriMapping, $term);
        }

        // Step 19.
        $containerMapping = [];

        // Step 13.5 allows a reverse property a container of null.
        if (array_key_exists('@container', $value) && !($reverse && $value['@container'] === null)) {
            $containerMapping = $this->containerMapping($value['@container'], $term, $isJsonLd10);

            if (in_array('@type', $containerMapping, true)) {
                $typeMapping ??= '@id';

                if ($typeMapping !== '@id' && $typeMapping !== '@vocab') {
                    throw new JsonLdError(ErrorCode::InvalidTypeMapping, $term);
                }
            }
        }

        // Step 20.
        $indexMapping = null;

        if (array_key_exists('@index', $value)) {
            if ($isJsonLd10 || !in_array('@index', $containerMapping, true) || !is_string($value['@index'])) {
                throw new JsonLdError(ErrorCode::InvalidTermDefinition, $term);
            }

            $index = IriExpander::expand($activeContext, $value['@index'], vocab: true);

            if ($index === null || !Grammar::isAbsoluteIri($index)) {
                throw new JsonLdError(ErrorCode::InvalidTermDefinition, $term);
            }

            $indexMapping = $value['@index'];
        }

        // Step 21.
        if (array_key_exists('@context', $value)) {
            if ($isJsonLd10) {
                throw new JsonLdError(ErrorCode::InvalidTermDefinition, $term);
            }

            try {
                $this->process(
                    $activeContext->build(),
                    $value['@context'],
                    $baseUrl,
                    $remoteContexts,
                    overrideProtected: true,
                    validateScopedContext: false,
                );
            } catch (JsonLdError $error) {
                throw new JsonLdError(ErrorCode::InvalidScopedContext, $term, $error);
            }
        }

        // Step 22.
        $hasLanguageMapping = array_key_exists('@language', $value) && !array_key_exists('@type', $value);

        if ($hasLanguageMapping && $value['@language'] !== null && !is_string($value['@language'])) {
            throw new JsonLdError(ErrorCode::InvalidLanguageMapping, $term);
        }

        // Step 23.
        $hasDirectionMapping = array_key_exists('@direction', $value) && !array_key_exists('@type', $value);

        if ($hasDirectionMapping && !in_array($value['@direction'], [null, 'ltr', 'rtl'], true)) {
            throw new JsonLdError(ErrorCode::InvalidBaseDirection, $term);
        }

        // Step 24.
        $nestValue = null;

        if (array_key_exists('@nest', $value)) {
            if ($isJsonLd10) {
                throw new JsonLdError(ErrorCode::InvalidTermDefinition, $term);
            }

            $nestValue = $value['@nest'];

            if (!is_string($nestValue) || ($nestValue !== '@nest' && Keywords::isKeyword($nestValue))) {
                throw new JsonLdError(ErrorCode::InvalidNestValue, $term);
            }
        }

        // Step 25.
        if (array_key_exists('@prefix', $value)) {
            if ($isJsonLd10 || str_contains($term, ':') || str_contains($term, '/')) {
                throw new JsonLdError(ErrorCode::InvalidTermDefinition, $term);
            }

            if (!is_bool($value['@prefix'])) {
                throw new JsonLdError(ErrorCode::InvalidPrefixValue, $term);
            }

            $prefix = $value['@prefix'];

            if ($prefix && $iriMapping !== null && Keywords::isKeyword($iriMapping)) {
                throw new JsonLdError(ErrorCode::InvalidTermDefinition, $term);
            }
        }

        // Step 26.
        $this->rejectUnknownEntries($value, $term);

        $definition = new TermDefinition(
            iriMapping: $iriMapping,
            prefix: $prefix,
            protected: $protected,
            reverse: $reverse,
            baseUrl: array_key_exists('@context', $value) ? $baseUrl : null,
            hasContext: array_key_exists('@context', $value),
            context: $value['@context'] ?? null,
            containerMapping: $containerMapping,
            hasDirectionMapping: $hasDirectionMapping,
            directionMapping: $hasDirectionMapping && is_string($value['@direction']) ? $value['@direction'] : null,
            indexMapping: $indexMapping,
            hasLanguageMapping: $hasLanguageMapping,
            languageMapping: $hasLanguageMapping && is_string($value['@language']) ? $value['@language'] : null,
            nestValue: $nestValue,
            typeMapping: $typeMapping,
        );

        // Step 27.
        $definition = $this->keepProtected($definition, $previousDefinition, $overrideProtected, $term);

        // Step 28.
        $activeContext->set($term, $definition);
        $defined[$term] = true;
    }

    /**
     * Step 26 of Create Term Definition
     *
     * @param array<mixed> $value The expanded term definition
     */
    private function rejectUnknownEntries(array $value, string $term): void
    {
        if (count(array_diff(array_keys($value), self::TERM_DEFINITION_KEYWORDS)) > 0) {
            throw new JsonLdError(ErrorCode::InvalidTermDefinition, $term);
        }
    }

    /**
     * Step 27 of Create Term Definition: a protected term may be redefined
     * only as it already is, and it stays protected
     */
    private function keepProtected(
        TermDefinition $definition,
        ?TermDefinition $previousDefinition,
        bool $overrideProtected,
        string $term,
    ): TermDefinition {
        if ($overrideProtected || $previousDefinition === null || !$previousDefinition->protected) {
            return $definition;
        }

        if (!$definition->equalsExceptProtected($previousDefinition)) {
            throw new JsonLdError(ErrorCode::ProtectedTermRedefinition, $term);
        }

        return $previousDefinition;
    }

    /**
     * Step 4 of Create Term Definition: `@type` may be redefined only to make
     * it a set or to protect it
     */
    private function checkTypeRedefinition(mixed $value, bool $isJsonLd10): void
    {
        $entries = $value instanceof stdClass ? get_object_vars($value) : [];

        if (
            $isJsonLd10
            || count($entries) === 0
            || count(array_diff(array_keys($entries), ['@container', '@protected'])) > 0
            || (array_key_exists('@container', $entries) && $entries['@container'] !== '@set')
        ) {
            throw new JsonLdError(ErrorCode::KeywordRedefinition, '@type');
        }
    }

    /**
     * An ignored term leaves the active context as it was. Step 6 has already
     * removed any earlier definition of the term by the time steps 13.3 and
     * 14.2.2 return, so it is put back, as the reference processors put it
     * back. Otherwise, in lenient mode, a later context could delete a
     * protected term by giving it an `@id` that has the form of a keyword.
     */
    private function restore(
        ActiveContextBuilder $activeContext,
        string $term,
        ?TermDefinition $previousDefinition,
    ): void {
        if ($previousDefinition !== null) {
            $activeContext->set($term, $previousDefinition);
        }
    }

    /**
     * The specification ignores a term when it, or the IRI it maps to, has the
     * form of a keyword without being one. Strict mode refuses instead.
     */
    private function ignoreReservedTerm(string $term): void
    {
        if ($this->options->strict) {
            throw new DataLoss(DataLossCondition::ReservedTerm, $term);
        }
    }

    /**
     * Step 12 of Create Term Definition
     *
     * @param Closure(string): void $define
     */
    private function typeMapping(
        ActiveContextBuilder $activeContext,
        mixed $type,
        string $term,
        Closure $define,
        bool $isJsonLd10,
    ): string {
        if (!is_string($type)) {
            throw new JsonLdError(ErrorCode::InvalidTypeMapping, $term);
        }

        $type = IriExpander::expand($activeContext, $type, vocab: true, define: $define);

        if (($type === '@json' || $type === '@none') && !$isJsonLd10) {
            return $type;
        }

        if ($type === '@id' || $type === '@vocab' || ($type !== null && Grammar::isAbsoluteIri($type))) {
            return $type;
        }

        throw new JsonLdError(ErrorCode::InvalidTypeMapping, $term);
    }

    /**
     * Step 13 of Create Term Definition: the IRI of a reverse property
     *
     * Returns `null` when the term is to be ignored because the `@reverse`
     * value has the form of a keyword.
     *
     * @param array<mixed> $value The expanded term definition
     * @param Closure(string): void $define
     */
    private function reverseIriMapping(
        ActiveContextBuilder $activeContext,
        array $value,
        string $term,
        Closure $define,
    ): ?string {
        // Step 13.1.
        if (array_key_exists('@id', $value) || array_key_exists('@nest', $value)) {
            throw new JsonLdError(ErrorCode::InvalidReverseProperty, $term);
        }

        // Step 13.2.
        if (!is_string($value['@reverse'])) {
            throw new JsonLdError(ErrorCode::InvalidIriMapping, $term);
        }

        // Step 13.3.
        if (Keywords::hasKeywordForm($value['@reverse'])) {
            $this->ignoreReservedTerm($term);

            return null;
        }

        // Step 13.4.
        $iriMapping = IriExpander::expand($activeContext, $value['@reverse'], vocab: true, define: $define);

        if ($iriMapping === null || !(Grammar::isAbsoluteIri($iriMapping) || str_starts_with($iriMapping, '_:'))) {
            throw new JsonLdError(ErrorCode::InvalidIriMapping, $term);
        }

        // Step 13.5.
        $container = $value['@container'] ?? null;

        if ($container !== null && $container !== '@set' && $container !== '@index') {
            throw new JsonLdError(ErrorCode::InvalidReverseProperty, $term);
        }

        return $iriMapping;
    }

    /**
     * Step 19 of Create Term Definition: the container mapping, as a list in
     * code point order
     *
     * @return list<string>
     */
    private function containerMapping(mixed $container, string $term, bool $isJsonLd10): array
    {
        $invalid = new JsonLdError(ErrorCode::InvalidContainerMapping, $term);

        // Step 19.2.
        if ($isJsonLd10 && (!is_string($container) || in_array($container, ['@graph', '@id', '@type'], true))) {
            throw $invalid;
        }

        // Step 19.1.
        $keywords = [];

        foreach (is_array($container) ? $container : [$container] as $keyword) {
            if (
                !is_string($keyword)
                || !in_array($keyword, self::CONTAINER_KEYWORDS, true)
                || in_array($keyword, $keywords, true)
            ) {
                throw $invalid;
            }

            $keywords[] = $keyword;
        }

        if ($keywords === []) {
            throw $invalid;
        }

        $others = array_diff($keywords, ['@set']);

        if (in_array('@list', $keywords, true) && count($keywords) > 1) {
            throw $invalid;
        }

        if (in_array('@graph', $others, true)) {
            $others = array_values(array_diff($others, ['@graph']));

            if ($others !== [] && $others !== ['@id'] && $others !== ['@index']) {
                throw $invalid;
            }
        } elseif (count($others) > 1) {
            throw $invalid;
        }

        // Step 19.3.
        sort($keywords, SORT_STRING);

        return $keywords;
    }

    private function isKeywordIriOrBlankNode(string $value): bool
    {
        return Keywords::isKeyword($value) || Grammar::isAbsoluteIri($value) || str_starts_with($value, '_:');
    }

    /**
     * Step 5.2.1, and the same resolution for `@import`
     */
    private function resolveContextUrl(string $reference, ?string $baseUrl): string
    {
        if ($baseUrl !== null) {
            return IriResolver::resolve($reference, $baseUrl);
        }

        if (!Grammar::isAbsoluteIri($reference)) {
            throw new JsonLdError(ErrorCode::LoadingDocumentFailed, $reference);
        }

        return $reference;
    }

    /**
     * Steps 5.2.4 and 5.2.5: the document for a context URL, from the loader
     * the first time and from memory after that
     *
     * Documents from the loader are not subject to the limits.
     *
     * @return array{string, mixed} The document URL and the document in the
     *     internal form
     */
    private function dereference(string $url): array
    {
        if (!array_key_exists($url, $this->dereferenced)) {
            try {
                $loaded = $this->options->documentLoader->load($url);
                $reader = new DocumentReader(new Limits(PHP_INT_MAX, PHP_INT_MAX));
                $this->dereferenced[$url] = [$loaded->documentUrl, $reader->read($loaded->document)];
            } catch (Throwable $throwable) {
                if (
                    $throwable instanceof JsonLdError
                    && $throwable->errorCode === ErrorCode::LoadingRemoteContextFailed
                ) {
                    throw $throwable;
                }

                throw new JsonLdError(ErrorCode::LoadingRemoteContextFailed, $url, $throwable);
            }
        }

        return $this->dereferenced[$url];
    }
}
