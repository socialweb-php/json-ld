# socialweb/json-ld Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Processor::expand()`, `Processor::toRdf()`, `Context\BundledDocumentLoader::withDocument()`, and `Context\LoadedDocument` accept a `JsonSerializable`, which is read as what its `jsonSerialize()` returns.
- `Context\BundledDocumentLoader::withoutDocument()` returns a loader without a document that `withDocument()` added.
- The text of the W3C Software and Document License beside the bundled contexts, in `src/Context/Bundled/LICENSE`.

### Changed

- The package requires socialweb/rdf `^0.2`.
- `Processor::__construct()` takes its settings as named parameters: `base`, `expandContext`, `processingMode`, `rdfDirection`, `strict`, `documentLoader`, `maxDepth`, `maxValues`, `maxContextOperations`, `forbidNamedGraphs`, `forbidIncludedBlocks`, `forbidReverseProperties`, and `requireSingleTopLevelNode`, with the defaults the options had.
- A document, an expand context, and a loaded document are typed `array | string | JsonSerializable | stdClass`. An object of any other class at the top is a `TypeError`; inside a document, or as a `stdClass` subclass, it is an `InvalidArgument`, raised before the document is read when the object lies within the limits on depth and on the number of values, and possibly when it lies beyond them.
- An expand context that is not a string is read once, when the processor is built, so `Processor::__construct()` raises its faults: `Exception\InvalidArgument`, `Exception\JsonLdError` with the code `loading document failed`, or `Exception\LimitExceeded`. Changing the object given as the expand context afterward does not change the processor. A string is a context URL and is still loaded when a document is expanded.
- Text that is not JSON, and a value that JSON text cannot express, such as a number that is not finite or a string that is not valid UTF-8, raise `Exception\JsonLdError` with the code `loading document failed`. The decoder's `JsonException` is the previous exception.
- `Exception\JsonLdError` extends `UnexpectedValueException`, has its code in `$reason` as an `Exception\JsonLdErrorCode` case, and has a message that starts with the code capitalized and puts the offending term, entry, or IRI in double quotes.
- `Exception\DataLoss` has its reason in `$reason` as an `Exception\DataLossReason` case, which has no backing string; its message names the case and puts the detail in double quotes.
- `Exception\LimitExceeded::$parameter` names the constructor parameter whose limit was exceeded and `Exception\LimitExceeded::$limit` holds its value; they were `$limit` and `$value`.
- `Context\BundledDocumentLoader::with()` is now `withDocument()`, and it raises `Exception\JsonLdError` with the code `invalid remote context` for a document that is not a JSON object or array.
- `NOTICE` holds the package's own notice only. Each bundled context names its source, copyright, and license in its own file.

### Removed

- `Options`, `Limits`, and `Restrictions`; pass their settings to `Processor::__construct()`. In place of `Restrictions::all()`, pass the four restrictions.
- `Exception\MalformedJson`; catch `Exception\JsonLdError` and check for `JsonLdErrorCode::LoadingDocumentFailed`.
- `ErrorCode` and `DataLossCondition`; use `Exception\JsonLdErrorCode` and `Exception\DataLossReason`.
- `Exception\JsonLdError::$errorCode` and `Exception\DataLoss::$condition`; read `$reason`.

### Fixed

- `Context\BundledDocumentLoader::load()` returns a copy of a document added with `withDocument()`, so changing what it returns changes neither the loader nor a loader made from it.

## [0.1.0] - 2026-10-12

### Added

- `Processor`, a JSON-LD 1.1 processor: `expand()` returns the expanded form as an `ExpandedDocument`, and `toRdf()` converts a document, or an `ExpandedDocument`, to a `SocialWeb\Rdf\Dataset` from the socialweb/rdf package. Both accept a JSON string, a `stdClass` tree, or nested arrays.
- `Options`, with `base`, `expandContext`, `processingMode`, `rdfDirection`, `strict`, `limits`, `restrictions`, and `documentLoader`, and the enums `ProcessingMode` and `RdfDirection`.
- Strict mode, on by default, which raises `DataLoss` wherever the JSON-LD algorithms would silently drop data, during expansion or conversion to RDF. The exception carries the drop point as a `DataLossCondition` case and the data that would have been dropped.
- A well-formed IRI, for conversion to RDF, for the `base` option, and for the datatype of a value, holds at most one `#`, which the RDF package's `Iri` does not require.
- `Limits`, with `maxDepth`, `maxValues`, and `maxContextOperations`, which bound the work of one call; exceeding one raises `LimitExceeded`.
- `Restrictions`, with `forbidNamedGraphs`, `forbidIncludedBlocks`, `forbidReverseProperties`, and `requireSingleTopLevelNode`; a document that uses a restricted feature raises `RestrictedFeature`.
- `Context\DocumentLoader`, `Context\LoadedDocument`, and the default loader `Context\BundledDocumentLoader`, with nine pinned contexts: ActivityStreams 2.0, Security Vocabulary v1 and v2, Identity Credentials v1, Data Integrity v1 and v2, Multikey v1, Controlled Identifiers v1, and Decentralized Identifiers v1. `with()` adds a context the caller supplies. Nothing is fetched from the network.
- Exceptions under `SocialWeb\JsonLd\Exception`, every one implementing the `JsonLdException` marker interface: `InvalidArgument`, `MalformedJson`, `JsonLdError` with the specification's error code as an `ErrorCode` case, `DataLoss`, `LimitExceeded`, and `RestrictedFeature`.

[unreleased]: https://github.com/socialweb-php/json-ld/compare/0.1.0...HEAD
[0.1.0]: https://github.com/socialweb-php/json-ld/commits/0.1.0/
