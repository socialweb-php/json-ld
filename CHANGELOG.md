# socialweb/json-ld Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 0.1.0 - 2026-10-12

### Added

- `Processor`, a JSON-LD 1.1 processor: `expand()` returns the expanded form as an `ExpandedDocument`, and `toRdf()` converts a document, or an `ExpandedDocument`, to a `SocialWeb\Rdf\Dataset` from the socialweb/rdf package. Both accept a JSON string, a `stdClass` tree, or nested arrays.
- `Options`, with `base`, `expandContext`, `processingMode`, `rdfDirection`, `strict`, `limits`, `restrictions`, and `documentLoader`, and the enums `ProcessingMode` and `RdfDirection`.
- Strict mode, on by default, which raises `DataLoss` wherever the JSON-LD algorithms would silently drop data, during expansion or conversion to RDF. The exception carries the drop point as a `DataLossCondition` case and the data that would have been dropped.
- A well-formed IRI, for conversion to RDF, for the `base` option, and for the datatype of a value, holds at most one `#`, which the RDF package's `Iri` does not require.
- `Limits`, with `maxDepth`, `maxValues`, and `maxContextOperations`, which bound the work of one call; exceeding one raises `LimitExceeded`.
- `Restrictions`, with `forbidNamedGraphs`, `forbidIncludedBlocks`, `forbidReverseProperties`, and `requireSingleTopLevelNode`; a document that uses a restricted feature raises `RestrictedFeature`.
- `Context\DocumentLoader`, `Context\LoadedDocument`, and the default loader `Context\BundledDocumentLoader`, with nine pinned contexts: ActivityStreams 2.0, Security Vocabulary v1 and v2, Identity Credentials v1, Data Integrity v1 and v2, Multikey v1, Controlled Identifiers v1, and Decentralized Identifiers v1. `with()` adds a context the caller supplies. Nothing is fetched from the network.
- Exceptions under `SocialWeb\JsonLd\Exception`, every one implementing the `JsonLdException` marker interface: `InvalidArgument`, `MalformedJson`, `JsonLdError` with the specification's error code as an `ErrorCode` case, `DataLoss`, `LimitExceeded`, and `RestrictedFeature`.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.
