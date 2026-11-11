<h1 align="center">socialweb/json-ld</h1>

<p align="center">
    <a href="https://github.com/socialweb-php/json-ld"><img src="https://img.shields.io/badge/source-socialweb/json--ld-blue.svg" alt="Source Code"></a>
    <a href="https://packagist.org/packages/socialweb/json-ld"><img src="https://img.shields.io/packagist/v/socialweb/json-ld.svg?label=release" alt="Download Package"></a>
    <a href="https://php.net"><img src="https://img.shields.io/packagist/php-v/socialweb/json-ld.svg?colorB=%238892BF" alt="PHP Programming Language"></a>
    <a href="https://github.com/socialweb-php/json-ld/blob/main/COPYING.LESSER"><img src="https://img.shields.io/packagist/l/socialweb/json-ld.svg?colorB=darkcyan" alt="Read License"></a>
    <a href="https://github.com/socialweb-php/json-ld/actions/workflows/continuous-integration.yml"><img src="https://img.shields.io/github/actions/workflow/status/socialweb-php/json-ld/continuous-integration.yml?branch=main&logo=github" alt="Build Status"></a>
    <a href="https://codecov.io/gh/socialweb-php/json-ld"><img src="https://img.shields.io/codecov/c/gh/socialweb-php/json-ld?label=codecov&logo=codecov" alt="Codecov Code Coverage"></a>
</p>

## About

socialweb/json-ld expands [JSON-LD 1.1][json-ld11] documents and converts them to RDF datasets, following the [JSON-LD 1.1 Processing Algorithms and API][json-ld11-api].

JSON-LD is JSON in which every property name and type is a short form of an Internationalized Resource Identifier (IRI), a name that has the form of a URL but is used only as a name; nothing needs to be published at it. Because an IRI is unique, it means the same thing in every document that uses it. The document's context says which IRI each short name means. A server that writes `"name": "Tamsin"` under the ActivityStreams context means `https://www.w3.org/ns/activitystreams#name`, and so does a server that writes `"as:name"` with a context that defines the `as:` prefix. So two servers can describe the same thing in different words, and a third can add terms of its own without a clash.

That flexibility is what makes the JSON alone hard to trust. When you receive a document, such as an activity from an ActivityPub server or a verifiable credential, its names mean nothing until you apply its context, and most documents give only the context's URL, not the context itself. Two senders may also write the same statement in different JSON: one as a plain string, `"name": "Tamsin"`, and the other as an object, `"name": {"@value": "Tamsin"}`. Their bytes differ, so a signature over one document's bytes cannot check the other, although both say the same thing.

This package applies a document's contexts and gives you the document in a form that does not depend on how the sender wrote it. Expansion is the first step: it replaces every short name with its full IRI and writes every value in one explicit form, so your application can read a property by one name, no matter who wrote it. Conversion to RDF is the second: it turns the expanded document into a `SocialWeb\Rdf\Dataset`, a set of plain statements, which [socialweb/rdf][rdf] can canonicalize into the same bytes for any two documents that say the same thing. That is what lets you hash or sign what a document says, not how it is written.

A JSON-LD processor reads documents from strangers, so this one is cautious. A processor that fetched contexts would request any URL a sender provided, and whoever controls that URL could change what your documents mean after you checked them. The processor in this package never makes a network request; it looks up each context URL in a set of contexts bundled with the package, such as ActivityStreams and the W3C security vocabularies, and you can add your own. By default, it refuses a document that contains a part the JSON-LD algorithms would silently leave out of the result, such as a property no context defines, since a signature would not cover that part. It also limits how deeply a document may nest, how many values it may contain, and how much work its contexts may take to process.

Compaction, flattening, framing, conversion from RDF, and fetching documents are left to other libraries, and canonicalization and N-Quads are socialweb/rdf's job. socialweb/json-ld depends on socialweb/rdf and nothing else.

This project adheres to a [code of conduct](CODE_OF_CONDUCT.md). By participating in this project and its community, you are expected to uphold this code.

## Installation

Install this package as a dependency using [Composer](https://getcomposer.org).

```bash
composer require socialweb/json-ld
```

## Usage

### Converting a document so that it can be hashed or signed

You have a JSON-LD document and want to hash or sign what it says, not how it is written. That takes three steps. `Processor::toRdf()` converts the document to a dataset. The `Canonicalizer` from socialweb/rdf then labels the dataset's blank nodes, the nodes with no IRI of their own, in a way that depends only on the data. Finally, `toNQuads()` on the result writes the dataset as canonical [N-Quads][n-quads], a plain-text format with one statement per line, with the lines sorted. Any copy of the document that says the same thing gives the same string, so that is the string you hash.

The examples in this README follow a tabletop role-playing game. Haruto Fuwa is the gamemaster and runs the sessions. Amara Okafor and Diego Castillo are players, and their characters are Oriel and Kaelen. The two documents below each record the same session, session 7: one written by the gamemaster and one by a player. They use different contexts, different property names, and a different order, and they still give the same string.

```php
use SocialWeb\JsonLd\Processor;
use SocialWeb\Rdf\Canonicalization\Canonicalizer;

$fromTheGamemaster = <<<'JSON'
    {
        "@context": {"@vocab": "https://example.com/ns/game#"},
        "@id": "https://example.com/sessions/7",
        "@type": "Session",
        "gamemaster": "Haruto Fuwa",
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
        "game:gamemaster": "Haruto Fuwa"
    }
    JSON;

$processor = new Processor();
$canonicalizer = new Canonicalizer();

$first = $canonicalizer->canonicalize($processor->toRdf($fromTheGamemaster))->toNQuads();
$second = $canonicalizer->canonicalize($processor->toRdf($fromAPlayer))->toNQuads();

echo $first;
echo $first === $second ? "The two documents say the same thing.\n" : "The two documents say different things.\n";
echo hash('sha256', $first), "\n";
```

This prints:

```text
<https://example.com/sessions/7> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <https://example.com/ns/game#Session> .
<https://example.com/sessions/7> <https://example.com/ns/game#gamemaster> "Haruto Fuwa" .
<https://example.com/sessions/7> <https://example.com/ns/game#player> _:c14n0 .
<https://example.com/sessions/7> <https://example.com/ns/game#player> _:c14n1 .
_:c14n0 <https://example.com/ns/game#name> "Diego Castillo" .
_:c14n0 <https://example.com/ns/game#plays> "Kaelen" .
_:c14n1 <https://example.com/ns/game#name> "Amara Okafor" .
_:c14n1 <https://example.com/ns/game#plays> "Oriel" .
The two documents say the same thing.
9bd8f20c803507bd31116664e9968d5b127bf531968738b047658626d7143e67
```

Each line is one statement: a subject, a property, and a value. The two players have no `@id`, so each becomes a blank node, and the canonicalizer labels them `_:c14n0` and `_:c14n1`. Which player gets which label depends on what is said about them, not on where they appear in the document, so both documents give the same lines.

`toRdf()` and `expand()` accept a document in any of these forms:

- a JSON string;
- a `stdClass` tree, as `json_decode()` returns by default;
- nested arrays, in which a PHP list is a JSON array and any other array is a JSON object;
- a `JsonSerializable`, read as what its `jsonSerialize()` returns.

`toRdf()` also accepts the `ExpandedDocument` that `expand()` returns, and does not expand it again.

> [!WARNING]
> A JSON-LD list becomes a chain of blank nodes in RDF. A list in which the same value appears twice in a row, such as `[4, 17, 17, 2]`, makes two blank nodes that the canonicalizer cannot distinguish by their neighbors alone, and with its default work factor of 1 it then raises `SocialWeb\Rdf\Exception\LimitExceeded`. Construct it with `new Canonicalizer(workFactor: 2)` when your documents may hold such lists. The socialweb/rdf README says what the work factor bounds and when to raise it.

### Supplying the contexts your documents reference

Most documents give only their context's URL, so the processor needs another way to get the context itself. It never fetches one from the network. Instead, it asks its document loader, an object that takes a URL and returns the document for it. The default loader, `BundledDocumentLoader`, has copies of these contexts and returns each one for its `https` URL and for the same URL with `http`:

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

For any other URL, the loader raises `SocialWeb\JsonLd\Exception\JsonLdError` with the code `loading remote context failed`, and the processor refuses the document.

When your documents use a context of your own, or one missing from this list, give the loader a copy. `withDocument()` takes the URL exactly as your documents write it and the context document, in any form `expand()` accepts, and returns a new loader with the context added. Pass that loader to the processor as `documentLoader`.

Here, the game's own context says that every short name belongs to the game's vocabulary, and that the value of `gamemaster` is an IRI, not a string.

```php
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\Processor;

$context = <<<'JSON'
    {
        "@context": {
            "@vocab": "https://example.com/ns/game#",
            "gamemaster": {"@type": "@id"}
        }
    }
    JSON;

$document = <<<'JSON'
    {
        "@context": "https://example.com/contexts/game/v1",
        "@id": "https://example.com/sessions/7",
        "@type": "Session",
        "gamemaster": "https://example.com/people/haruto",
        "location": "The inn of Gable the innkeeper"
    }
    JSON;

$loader = (new BundledDocumentLoader())->withDocument('https://example.com/contexts/game/v1', $context);
$processor = new Processor(documentLoader: $loader);

echo $processor->expand($document)->toJson(), "\n";
```

This prints:

```text
[{"@id":"https://example.com/sessions/7","@type":["https://example.com/ns/game#Session"],"https://example.com/ns/game#gamemaster":[{"@id":"https://example.com/people/haruto"}],"https://example.com/ns/game#location":[{"@value":"The inn of Gable the innkeeper"}]}]
```

In the expanded document, `location` is now the full IRI `https://example.com/ns/game#location`, and the value of `gamemaster` is an `@id`, a reference to the gamemaster, not a string.

`expand()` returns an `ExpandedDocument`; `toJson()` writes it as JSON text, and `jsonSerialize()` returns it as a list of node objects, with JSON objects as `stdClass`.

If you add a context for a URL in the table above, the processor uses yours instead of the package's copy. `withoutDocument()` takes a URL and returns a new loader without the context you added for it. If the package has its own copy of that context, the loader uses that copy again. The package's own copies cannot be removed. The loader reads a context you add without the processor's limits on depth and number of values, since it comes from you and not from a sender.

To get contexts from somewhere else, such as a cache your application fills, implement `SocialWeb\JsonLd\Context\DocumentLoader`. Its one method, `load(string $url): LoadedDocument`, receives the URL a document names for its context, and your loader decides where to find that context. It returns a `LoadedDocument` that holds the context and the URL that relative IRIs in the context are resolved against, most often the same URL.

### Deciding what happens to data the algorithms would leave out

The JSON-LD algorithms leave out of their result any part of a document they cannot give a meaning. Examples are a property that no context defines, a value at the top level with no property to belong to, and, in conversion to RDF, an `@id` such as `characters/tamsin` that stays relative because no base IRI was given. That part is still in the JSON you received, and your application may still read it from there, but a signature over the result says nothing about it. So, by default, the processor is strict. It raises `SocialWeb\JsonLd\Exception\DataLoss` at the first part it would leave out. Pass `strict: false` for the standard's behavior when you only want to read what the document defines.

Here, Tamsin's character sheet has a `class` property that its context does not define.

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

The first line is the exception's message. Its `reason` property is a `DataLossReason` case that names the kind of data, here `UndefinedProperty`, and its `detail` property holds the part that would have been left out. The second line shows the same document expanded with `strict: false`, without `class`.

### Limiting the size of the documents you accept

A document from a stranger could be designed to cost you time or memory. The processor's constructor takes three limits, each a named parameter with a default:

- `maxDepth`, 128 by default, limits how deeply arrays and objects may nest and how long a chain of contexts or term definitions may be.
- `maxValues`, 100,000 by default, limits the number of JSON values in a document.
- `maxContextOperations`, 1,000,000 by default, limits the work of processing contexts in one call, since a small document can name contexts that are very expensive to process.

Exceeding a limit raises `SocialWeb\JsonLd\Exception\LimitExceeded`. Its `parameter` property names the limit, and its `limit` property holds the limit's value. One case differs: a chain of contexts that name one another by URL and is longer than `maxDepth` raises `SocialWeb\JsonLd\Exception\JsonLdError` with the code `context overflow`.

### Making the JSON you read match the data you sign

Some JSON-LD features let a document make a statement somewhere other than where it appears in the JSON. In this character sheet, `member` sits inside Tamsin's object. An application that reads the JSON in the usual way, where a key says something about the object it sits in, would take it to mean that Tamsin has the quest as a member. However, because it is inside `@reverse`, the dataset says the opposite: the quest has Tamsin as a member, so Tamsin is a member of the quest. A signature over the dataset checks the dataset's statement, not the one the application took from the JSON.

```php
use SocialWeb\JsonLd\Exception\RestrictedFeature;
use SocialWeb\JsonLd\Processor;

$document = <<<'JSON'
    {
        "@context": {"@vocab": "https://example.com/ns/game#"},
        "@id": "https://example.com/characters/tamsin",
        "name": "Tamsin",
        "@reverse": {"member": {"@id": "https://example.com/quests/spider-caves"}}
    }
    JSON;

echo (new Processor())->toRdf($document)->toNQuads();

try {
    (new Processor(forbidReverseProperties: true))->toRdf($document);
} catch (RestrictedFeature $exception) {
    echo $exception->getMessage(), "\n";
}
```

This prints:

```text
<https://example.com/characters/tamsin> <https://example.com/ns/game#name> "Tamsin" .
<https://example.com/quests/spider-caves> <https://example.com/ns/game#member> <https://example.com/characters/tamsin> .
The document uses a restricted feature (forbidReverseProperties): https://example.com/characters/tamsin
```

The statement whose subject is the quest comes from the `@reverse` entry. Four restrictions, all off by default, refuse such features:

- `forbidNamedGraphs` refuses a `@graph` entry inside a node, which puts statements in a separate, named group.
- `forbidIncludedBlocks` refuses an `@included` entry, which adds nodes beside the one the JSON describes.
- `forbidReverseProperties` refuses an `@reverse` entry, including one that comes from a context term.
- `requireSingleTopLevelNode` refuses a document whose top level, once expanded, is not exactly one node.

Turn these on when your application reads the JSON itself after checking a hash or signature over the dataset. Each one refuses a feature that would let the JSON and the dataset say different things. A document that uses a refused feature raises `SocialWeb\JsonLd\Exception\RestrictedFeature`.

### Handling errors

Every exception the package throws implements `SocialWeb\JsonLd\Exception\JsonLdException`, so one `catch` clause covers them all. The example below gives a processor small limits and one restriction, then shows the exception each of four documents raises.

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

`JsonLdError` is the exception for a document that breaks a rule of JSON-LD, including text that is not JSON. Its `reason` property is a `JsonLdErrorCode` case, backed by the error code the specification gives that rule, and its message starts with that code. An `InvalidArgument` means a mistake in the calling code, such as a limit below 1, or a document that contains a PHP object with no JSON form.

### Other processor options

The processor's constructor takes four more named parameters:

- `base` is the IRI that relative references in a document, such as `characters/tamsin`, are resolved against. By default, it is `null`, and relative references stay as they are.
- `expandContext` is a context applied before the document's own, for documents that leave out a context your application expects. Give it as an array, a `stdClass`, or a `JsonSerializable`, or as a string that is a context URL, not JSON text. The document loader supplies the context for a URL.
- `processingMode` set to `ProcessingMode::JsonLd10` refuses documents that use JSON-LD 1.1 features. The default is JSON-LD 1.1.
- `rdfDirection` says how a value's text direction, such as right to left for Arabic or Hebrew, is written in RDF: `RdfDirection::I18nDatatype` or `RdfDirection::CompoundLiteral`. By default, conversion to RDF leaves the direction out, so in strict mode the processor refuses a value that has one.

## Contributing

Contributions are welcome! To contribute, please familiarize yourself with [CONTRIBUTING.md](CONTRIBUTING.md).

## Coordinated vulnerability disclosure policy

Keeping user information safe and secure is a top priority, and we welcome the contribution of external security researchers. If you believe you've found a security issue in software that is maintained in this repository, please read [SECURITY.md](SECURITY.md) for instructions on submitting a vulnerability report.

## Copyright and license

socialweb/json-ld is copyright © socialweb/json-ld Contributors and licensed for use under the terms of the GNU Lesser General Public License (LGPL-3.0-or-later) as published by the Free Software Foundation. Please see [COPYING.LESSER](COPYING.LESSER), [COPYING](COPYING), and [NOTICE](NOTICE) for more information.

[json-ld11]: https://www.w3.org/TR/json-ld11/
[json-ld11-api]: https://www.w3.org/TR/json-ld11-api/
[n-quads]: https://www.w3.org/TR/n-quads/
[rdf]: https://github.com/socialweb-php/rdf
