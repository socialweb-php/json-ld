<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\ProcessingMode;
use SocialWeb\JsonLd\Processor;
use SocialWeb\JsonLd\RdfDirection;

use function is_string;

/**
 * Builds the processor for an entry of the W3C suite from the entry's options
 *
 * The input's URL is the base, unless the entry gives another. The
 * `produceGeneralizedRdf` option has no counterpart here, because the RDF
 * model cannot hold a blank node predicate, and `useJCS` has none because
 * JSON literals are always written in canonical form.
 */
final class W3cProcessor
{
    public static function build(W3cEntry $entry, bool $strict): Processor
    {
        $base = $entry->option['base'] ?? null;
        $expandContext = $entry->option['expandContext'] ?? null;
        $rdfDirection = $entry->option['rdfDirection'] ?? null;

        return new Processor(new Options(
            base: is_string($base) ? $base : $entry->inputUrl,
            expandContext: is_string($expandContext) ? W3cManifest::BASE_IRI . $expandContext : null,
            processingMode: self::processingMode($entry),
            rdfDirection: is_string($rdfDirection) ? RdfDirection::from($rdfDirection) : null,
            strict: $strict,
            documentLoader: new FixtureDocumentLoader(),
        ));
    }

    public static function processingMode(W3cEntry $entry): ProcessingMode
    {
        $processingMode = $entry->option['processingMode'] ?? null;

        return is_string($processingMode) ? ProcessingMode::from($processingMode) : ProcessingMode::JsonLd11;
    }
}
