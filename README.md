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

socialweb/json-ld expands [JSON-LD 1.1][json-ld11] documents and converts them to RDF datasets, following the [JSON-LD 1.1 Processing Algorithms and API][json-ld11-api].

You receive a JSON document from another system, such as an activity from an ActivityPub server or a verifiable credential, and you need to know what it says, or to check a signature over it. The JSON alone does not tell you. The same property may have a different name in each sender's document, a value may be a string in one and an object in the next, and what a name means depends on a context the document only references by URL. Two documents with different bytes may say the same thing, and two with nearly the same bytes may not.

This package applies a document's contexts and gives you one of two forms that do not depend on how the sender wrote it. Expansion returns the document with every property and type as a full IRI and every value in one explicit form. Conversion to RDF returns a `SocialWeb\Rdf\Dataset` from [socialweb/rdf][rdf], which that package can canonicalize, so that you can hash or sign the data and not the bytes. The processor passes the W3C `expand` and `toRdf` test suites for JSON-LD 1.1, apart from the one `toRdf` entry that requires generalized RDF.

A JSON-LD processor reads documents from strangers, so this one is cautious. It is strict by default: where the JSON-LD algorithms would silently drop part of a document, such as a property no context defines, it refuses the document instead. It never makes a network request; a context that a document references by URL comes from a set of pinned contexts bundled with the package, which you can add to. It bounds the work a document may ask for. Compaction, flattening, framing, conversion from RDF, and fetching documents are left to other libraries, and canonicalization and N-Quads are socialweb/rdf's job. The package depends on socialweb/rdf and nothing else.

This project adheres to a [code of conduct](CODE_OF_CONDUCT.md). By participating in this project and its community, you are expected to uphold this code.

## Installation

Install this package as a dependency using [Composer](https://getcomposer.org).

```bash
composer require socialweb/json-ld
```

## Usage

### Converting a document so that it can be hashed or signed

You have a JSON-LD document and want bytes that anyone holding the same data can reproduce, whatever their copy of the document looks like. `Processor::toRdf()` converts the document to a dataset, the `Canonicalizer` of socialweb/rdf gives the dataset's blank nodes labels that depend only on the data, and `toNQuads()` on its result writes the sorted canonical N-Quads. Hash that string.

The two documents below record the same game session. They use different contexts, different property names, and a different order, and they still give the same bytes.

```php
use SocialWeb\JsonLd\Processor;
use SocialWeb\Rdf\Canonicalization\Canonicalizer;

$fromTheGameMaster = <<<'JSON'
    {
        "@context": {"@vocab": "https://example.com/ns/game#"},
        "@id": "https://example.com/sessions/7",
        "@type": "Session",
        "master": "Haruto Fuwa",
        "player": [
            {"name": "Amara Okafor", "plays": "Oriel"},
            {"name": "Diego Castillo", "plays": "Kaelen"}
        ]
    }
    JSON;

$fromAPlayer = <<<'JSON'
    {
        "@context": {
            "game": "https://example.com/ns/game#",
            "players": {"@id": "game:player"}
        },
        "@id": "https://example.com/sessions/7",
        "@type": "game:Session",
        "players": [
            {"game:plays": "Kaelen", "game:name": "Diego Castillo"},
            {"game:name": "Amara Okafor", "game:plays": "Oriel"}
        ],
        "game:master": "Haruto Fuwa"
    }
    JSON;

$processor = new Processor();
$canonicalizer = new Canonicalizer();

$first = $canonicalizer->canonicalize($processor->toRdf($fromTheGameMaster))->toNQuads();
$second = $canonicalizer->canonicalize($processor->toRdf($fromAPlayer))->toNQuads();

echo $first;
echo $first === $second ? "The two documents are the same data.\n" : "The two documents differ.\n";
echo hash('sha256', $first), "\n";
```

This prints:

```text
<https://example.com/sessions/7> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <https://example.com/ns/game#Session> .
<https://example.com/sessions/7> <https://example.com/ns/game#master> "Haruto Fuwa" .
<https://example.com/sessions/7> <https://example.com/ns/game#player> _:c14n0 .
<https://example.com/sessions/7> <https://example.com/ns/game#player> _:c14n1 .
_:c14n0 <https://example.com/ns/game#name> "Diego Castillo" .
_:c14n0 <https://example.com/ns/game#plays> "Kaelen" .
_:c14n1 <https://example.com/ns/game#name> "Amara Okafor" .
_:c14n1 <https://example.com/ns/game#plays> "Oriel" .
The two documents are the same data.
1d059c4f98880a5566b606a7ed567739526ed1cec59cf7a3a5e144b6dd7da299
```

`toRdf()` and `expand()` accept a document as a JSON string, as a `stdClass` tree such as `json_decode()` returns by default, as nested arrays, in which a PHP list is a JSON array and any other array is a JSON object, or as a `JsonSerializable`, which is read as what its `jsonSerialize()` returns. `toRdf()` also accepts the `ExpandedDocument` that `expand()` returns, and does not expand it again.

> [!WARNING]
> A JSON-LD list becomes a chain of blank nodes in RDF. A list in which the same value appears twice in a row, such as `[4, 17, 17, 2]`, makes two blank nodes that the canonicalizer cannot distinguish by their neighbors alone, and with its default work factor of 1 it then raises `SocialWeb\Rdf\Exception\LimitExceeded`. Construct it with `new Canonicalizer(workFactor: 2)` when your documents may hold such lists. The socialweb/rdf README says what the work factor bounds and when to raise it.

### Supplying the contexts your documents reference

Most documents do not include their context; they name it by URL. The processor never fetches one. It asks its document loader, and the default loader, `BundledDocumentLoader`, answers for these contexts, each by its `https` URL and by the same URL with `http`:

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

For any other URL the loader raises `SocialWeb\JsonLd\Exception\JsonLdError` with the code `loading remote context failed`, and the document is refused. When your documents use a context of your own, or one this list lacks, give the loader a copy: `withDocument()` takes the URL exactly as documents write it and the context document, in any form `expand()` accepts, and returns a new loader. Pass that loader to the processor as `documentLoader`.

```php
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\Processor;

$context = <<<'JSON'
    {
        "@context": {
            "@vocab": "https://example.com/ns/game#",
            "master": {"@type": "@id"}
        }
    }
    JSON;

$document = <<<'JSON'
    {
        "@context": "https://example.com/contexts/game/v1",
        "@id": "https://example.com/sessions/7",
        "@type": "Session",
        "master": "https://example.com/people/haruto",
        "location": "The inn of Gable the innkeeper"
    }
    JSON;

$loader = (new BundledDocumentLoader())->withDocument('https://example.com/contexts/game/v1', $context);
$processor = new Processor(documentLoader: $loader);

echo $processor->expand($document)->toJson(), "\n";
```

This prints:

```text
[{"@id":"https://example.com/sessions/7","@type":["https://example.com/ns/game#Session"],"https://example.com/ns/game#location":[{"@value":"The inn of Gable the innkeeper"}],"https://example.com/ns/game#master":[{"@id":"https://example.com/people/haruto"}]}]
```

A document added for the URL of a bundled context takes its place, and `withoutDocument()` returns a loader without a document you added. The loader does not apply the processor's limits to a document you add, since you vouch for it. To get contexts from somewhere else, such as a cache your application fills, implement `SocialWeb\JsonLd\Context\DocumentLoader`; its one method, `load(string $url): LoadedDocument`, returns the document and the URL it counts as loaded from.

### Deciding what happens to data the algorithms would drop

The JSON-LD algorithms drop what they cannot give a meaning: a property that no context defines, a value with no property, a relative IRI where RDF needs an absolute one. A signature made over the result then says nothing about the part that was dropped, although the receiver's application may still read it from the JSON. So the processor is strict by default and raises `SocialWeb\JsonLd\Exception\DataLoss` at the first such point. Pass `strict: false` to get the standard's behavior, when you only want to read what the document defines.

Here the character sheet has a `class` property that its context does not define.

```php
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Processor;

$document = <<<'JSON'
    {
        "@context": {"name": "https://example.com/ns/game#name"},
        "@id": "https://example.com/characters/tamsin",
        "name": "Tamsin",
        "class": "rogue"
    }
    JSON;

try {
    (new Processor())->expand($document);
} catch (DataLoss $exception) {
    echo $exception->getMessage(), "\n";
}

echo (new Processor(strict: false))->expand($document)->toJson(), "\n";
```

This prints:

```text
Data loss detected (UndefinedProperty): "class"
[{"@id":"https://example.com/characters/tamsin","https://example.com/ns/game#name":[{"@value":"Tamsin"}]}]
```

The exception's `reason` property is a `DataLossReason` case that names the kind of data, and its `detail` property holds all of what would have been dropped; the message quotes its first 256 characters. `expand()` returns an `ExpandedDocument`: `toJson()` writes it as JSON text, and `jsonSerialize()` returns it as a list of node objects, with JSON objects as `stdClass`.

### Bounding the work a document may ask for

A document from a stranger may be built to cost you time or memory, or to say one thing in its JSON and another in its RDF. The processor's constructor takes three limits and four restrictions, each a named parameter with a default.

The limits are `maxDepth`, 128 by default, which bounds how deeply arrays and objects nest and how long a chain of contexts or term definitions may be; `maxValues`, 100,000, which bounds the number of JSON values in a document; and `maxContextOperations`, 1,000,000, which bounds the work of processing contexts in one call, since a small document can make its contexts very expensive. Exceeding one raises `SocialWeb\JsonLd\Exception\LimitExceeded`, whose `parameter` property names the limit and whose `limit` property holds its value.

The restrictions are off by default. `forbidNamedGraphs`, `forbidIncludedBlocks`, and `forbidReverseProperties` each refuse one feature that lets a statement appear somewhere other than where the JSON tree suggests, and `requireSingleTopLevelNode` refuses a document whose top level, once expanded, is not exactly one node. Turn them on when you hash or sign the dataset but your application reads the JSON tree, so that the tree you read is the only one that gives that dataset. A document that uses a refused feature raises `SocialWeb\JsonLd\Exception\RestrictedFeature`.

```php
use SocialWeb\JsonLd\Exception\JsonLdException;
use SocialWeb\JsonLd\Processor;

$processor = new Processor(maxDepth: 3, maxValues: 1_000, forbidNamedGraphs: true);

$documents = [
    'too deep' => '{"@id": "ex:a", "ex:p": {"ex:q": {"ex:r": {"ex:s": 1}}}}',
    'a named graph' => '{"@id": "ex:g", "@graph": [{"@id": "ex:a", "ex:p": 1}], "ex:q": 1}',
    'not JSON' => '{"@id": "ex:a", ',
    'a bad context' => '{"@context": {"name": 42}, "name": "Thorne"}',
];

foreach ($documents as $label => $document) {
    try {
        $processor->toRdf($document);
    } catch (JsonLdException $exception) {
        echo $label, ': ', $exception::class, ': ', $exception->getMessage(), "\n";
    }
}
```

This prints:

```text
too deep: SocialWeb\JsonLd\Exception\LimitExceeded: The maxDepth limit of 3 was exceeded
a named graph: SocialWeb\JsonLd\Exception\RestrictedFeature: The document uses a restricted feature (forbidNamedGraphs): ex:g
not JSON: SocialWeb\JsonLd\Exception\JsonLdError: Loading document failed: the text is not valid JSON (Syntax error)
a bad context: SocialWeb\JsonLd\Exception\JsonLdError: Invalid term definition: "name"
```

Every exception the package throws implements `SocialWeb\JsonLd\Exception\JsonLdException`, so one `catch` clause covers them all. `JsonLdError` is the one for a document that breaks a rule of JSON-LD, including text that is not JSON; its `reason` property is a `JsonLdErrorCode` case, backed by the error code the specification gives that rule, and its message starts with that code. An `InvalidArgument` means a mistake in the calling code, such as a limit below 1 or a document that holds a PHP object JSON has no form for. The other constructor parameters are `base`, the IRI that relative references in a document are resolved against; `expandContext`, a context applied before the document's own; `processingMode`, to refuse JSON-LD 1.1 features; and `rdfDirection`, which says how a value's base direction is written in RDF.

## Contributing

Contributions are welcome! To contribute, please familiarize yourself with [CONTRIBUTING.md](CONTRIBUTING.md).

## Coordinated vulnerability disclosure policy

Keeping user information safe and secure is a top priority, and we welcome the contribution of external security researchers. If you believe you've found a security issue in software that is maintained in this repository, please read [SECURITY.md](SECURITY.md) for instructions on submitting a vulnerability report.

## Copyright and license

socialweb/json-ld is copyright © socialweb/json-ld Contributors and licensed for use under the terms of the GNU Lesser General Public License (LGPL-3.0-or-later) as published by the Free Software Foundation. Please see [COPYING.LESSER](COPYING.LESSER), [COPYING](COPYING), and [NOTICE](NOTICE) for more information.

[json-ld11]: https://www.w3.org/TR/json-ld11/
[json-ld11-api]: https://www.w3.org/TR/json-ld11-api/
[rdf]: https://github.com/socialweb-php/rdf
