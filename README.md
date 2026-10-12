<h1 align="center">socialweb/json-ld</h1>

<p align="center">
    <a href="https://github.com/socialweb-php/json-ld"><img src="https://img.shields.io/badge/source-socialweb/json--ld-blue.svg?style=flat-square" alt="Source Code"></a>
    <a href="https://packagist.org/packages/socialweb/json-ld"><img src="https://img.shields.io/packagist/v/socialweb/json-ld.svg?style=flat-square&label=release" alt="Download Package"></a>
    <a href="https://php.net"><img src="https://img.shields.io/packagist/php-v/socialweb/json-ld.svg?style=flat-square&colorB=%238892BF" alt="PHP Programming Language"></a>
    <a href="https://github.com/socialweb-php/json-ld/blob/main/COPYING.LESSER"><img src="https://img.shields.io/packagist/l/socialweb/json-ld.svg?style=flat-square&colorB=darkcyan" alt="Read License"></a>
    <a href="https://github.com/socialweb-php/json-ld/actions/workflows/continuous-integration.yml"><img src="https://img.shields.io/github/actions/workflow/status/socialweb-php/json-ld/continuous-integration.yml?branch=main&style=flat-square&logo=github" alt="Build Status"></a>
    <a href="https://codecov.io/gh/socialweb-php/json-ld"><img src="https://img.shields.io/codecov/c/gh/socialweb-php/json-ld?label=codecov&logo=codecov&style=flat-square" alt="Codecov Code Coverage"></a>
</p>

## About

socialweb/json-ld is a PHP processor for [JSON-LD 1.1][json-ld11]. It does two
things:

- it expands a JSON-LD document against its contexts, following the
  [JSON-LD 1.1 Processing Algorithms and API][json-ld11-api], and
- it converts a JSON-LD document to an RDF dataset, as a
  `SocialWeb\Rdf\Dataset` from the [socialweb/rdf][] package, so the result can
  be serialized as N-Quads or canonicalized and hashed.

The processor passes the W3C `expand` and `toRdf` test suites for JSON-LD 1.1,
apart from the one `toRdf` entry that requires generalized RDF. It is strict by
default: data that the JSON-LD algorithms would silently drop, such as a
property that no context defines, is an error unless the caller turns strict
mode off. It never uses the network; the contexts that a document references
by URL come from a bundled set of pinned W3C and w3id.org contexts, which the
caller can extend. It does bounded work on untrusted input, and it depends on
no package other than socialweb/rdf.

Not included, by design: compaction, flattening, framing, conversion from RDF,
a document loader that fetches contexts over the network, JSON-LD in HTML
script elements, generalized RDF, and the JSON-LD 1.2 drafts.

This project adheres to a [code of conduct](CODE_OF_CONDUCT.md).
By participating in this project and its community, you are expected to
uphold this code.

## Installation

Install this package as a dependency using [Composer](https://getcomposer.org).

``` bash
composer require socialweb/json-ld
```

## Usage

### Converting a document and canonicalizing the result

Convert the document to a dataset, canonicalize the dataset with socialweb/rdf,
and hash the canonical N-Quads. Two documents that mean the same thing, with
different contexts, key order, or blank node labels, produce the same bytes.

``` php
use SocialWeb\JsonLd\Processor;
use SocialWeb\Rdf\Canonicalization\Canonicalizer;

$document = <<<'JSON'
    {
        "@context": {"@vocab": "https://schema.org/"},
        "@id": "https://example.com/alice",
        "@type": "Person",
        "name": "Alice",
        "knows": {"@type": "Person", "name": "Bob"}
    }
    JSON;

$dataset = (new Processor())->toRdf($document);
$canonical = (new Canonicalizer())->canonicalize($dataset)->toNQuads();
$hash = hash('sha256', $canonical);

echo $canonical;
```

This prints:

``` text
<https://example.com/alice> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <https://schema.org/Person> .
<https://example.com/alice> <https://schema.org/knows> _:c14n0 .
<https://example.com/alice> <https://schema.org/name> "Alice" .
_:c14n0 <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <https://schema.org/Person> .
_:c14n0 <https://schema.org/name> "Bob" .
```

`toRdf()` accepts a JSON string, a `stdClass` tree as `json_decode()` returns
by default, nested arrays, in which a PHP list is a JSON array and any other
array is a JSON object, or an `ExpandedDocument` from `expand()`.

A JSON-LD list becomes a chain of blank nodes in RDF. A list in which the same
value appears twice in a row, such as `[1, 2, 2, 3]`, can make two blank nodes
that the canonicalizer cannot distinguish by their neighbors alone, and with
its default work factor of 1 it then raises
`SocialWeb\Rdf\Exception\IterationLimitExceeded`. A work factor of 2 allows
such lists:

``` php
$canonical = (new Canonicalizer(workFactor: 2))->canonicalize($dataset)->toNQuads();
```

`Processor` takes an `Options` object, every option of which has a default. The
options include `base`, for resolving relative references; `limits`, which
bound the depth and the size of a document and the work its contexts may take;
and `restrictions`, which refuse named graphs, `@included` blocks, reverse
properties, and documents with more than one top-level node, for a caller that
hashes a dataset but reads the JSON tree it came from.

### Adding a context to the bundled loader

The processor never fetches a context. A document that references a context by
URL gets it from the `DocumentLoader` in the options, and the default loader,
`BundledDocumentLoader`, knows these contexts, each by its `https` URL and its
`http` alias:

| Context | URL |
| --- | --- |
| ActivityStreams 2.0 | `https://www.w3.org/ns/activitystreams` |
| Security Vocabulary v1 | `https://w3id.org/security/v1` |
| Security Vocabulary v2 | `https://w3id.org/security/v2` |
| Identity Credentials v1 | `https://w3id.org/identity/v1` |
| Data Integrity v1 | `https://w3id.org/security/data-integrity/v1` |
| Data Integrity v2 | `https://w3id.org/security/data-integrity/v2` |
| Multikey v1 | `https://w3id.org/security/multikey/v1` |
| Controlled Identifiers v1 | `https://www.w3.org/ns/cid/v1` |
| Decentralized Identifiers v1 | `https://www.w3.org/ns/did/v1` |

Any other URL raises `SocialWeb\JsonLd\Exception\JsonLdError` with the code
`loading remote context failed`. To add a context, give the loader the URL and
the context document, as a JSON string, a `stdClass` tree, or nested arrays.
`with()` returns a new loader; an entry it adds replaces a bundled context with
the same URL.

``` php
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\Processor;

$context = <<<'JSON'
    {
        "@context": {
            "@vocab": "https://schema.org/",
            "knows": {"@type": "@id"}
        }
    }
    JSON;

$document = <<<'JSON'
    {
        "@context": "https://example.com/contexts/v1",
        "@id": "https://example.com/alice",
        "name": "Alice",
        "knows": "https://example.com/bob"
    }
    JSON;

$loader = (new BundledDocumentLoader())->with('https://example.com/contexts/v1', $context);
$processor = new Processor(new Options(documentLoader: $loader));

echo $processor->expand($document)->toJson(), "\n";
```

This prints:

``` text
[{"@id":"https://example.com/alice","https://schema.org/knows":[{"@id":"https://example.com/bob"}],"https://schema.org/name":[{"@value":"Alice"}]}]
```

A loader of your own implements `SocialWeb\JsonLd\Context\DocumentLoader`,
whose one method, `load(string $url): LoadedDocument`, returns the decoded
context document and the URL it counts as loaded from.

### Inspecting an expansion in strict mode

`expand()` returns an `ExpandedDocument`. Its `toJson()` method gives the
expanded document as JSON text, and `jsonSerialize()` gives it as a list of
node objects, with JSON objects as `stdClass`. Here the document has a
property that its context does not define. The JSON-LD algorithms drop such a
property, so in strict mode the processor refuses the document, and with
strict mode off it expands the rest:

``` php
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\Processor;

$document = <<<'JSON'
    {
        "@context": {"name": "https://schema.org/name"},
        "@id": "https://example.com/alice",
        "name": "Alice",
        "nickname": "Ally"
    }
    JSON;

try {
    (new Processor())->expand($document);
} catch (DataLoss $e) {
    echo $e->getMessage(), "\n";
}

$expanded = (new Processor(new Options(strict: false)))->expand($document);

echo $expanded->toJson(), "\n";
```

This prints:

``` text
Data loss detected (undefined property): nickname
[{"@id":"https://example.com/alice","https://schema.org/name":[{"@value":"Alice"}]}]
```

`DataLoss` carries the condition as a `DataLossCondition` case in its
`condition` property and the term, value, or IRI that would have been dropped
in its `detail` property. Every exception the package throws implements
`SocialWeb\JsonLd\Exception\JsonLdException`, so one `catch` clause covers them
all.

## Contributing

Contributions are welcome! To contribute, please familiarize yourself with
[CONTRIBUTING.md](CONTRIBUTING.md).

## Coordinated Disclosure

Keeping user information safe and secure is a top priority, and we welcome the
contribution of external security researchers. If you believe you've found a
security issue in software that is maintained in this repository, please read
[SECURITY.md](SECURITY.md) for instructions on submitting a vulnerability report.

## Copyright and License

socialweb/json-ld is copyright © socialweb/json-ld Contributors
and licensed for use under the terms of the
GNU Lesser General Public License (LGPL-3.0-or-later) as published by the Free
Software Foundation. Please see [COPYING.LESSER](COPYING.LESSER),
[COPYING](COPYING), and [NOTICE](NOTICE) for more information.

[json-ld11]: https://www.w3.org/TR/json-ld11/
[json-ld11-api]: https://www.w3.org/TR/json-ld11-api/
[socialweb/rdf]: https://github.com/socialweb-php/rdf
