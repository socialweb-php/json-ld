# Copied test suites and contexts

The directories here hold material copied from other projects: three sets of test data, and the JSON-LD contexts that the package bundles. Each file is kept byte for byte as its source published it. The test data is not part of the library. All of it is left out of distribution archives; the library reads the contexts from the files generated under `src/Context/Bundled/`.

| Directory | Project | Source | License |
| --- | --- | --- | --- |
| `contexts/` | Nine JSON-LD contexts, each retrieved from the URL it is published at | [`contexts/manifest.json`](contexts/manifest.json) gives the URL, the source document, and the copyright of each one | [W3C Software and Document License](https://www.w3.org/copyright/software-license-2023/), recorded for each context in `contexts/manifest.json`, in `contexts/LICENSE` |
| `jcs/` | Test data for RFC 8785, the JSON Canonicalization Scheme (JCS) | <https://github.com/cyberphone/json-canonicalization> | [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0), in `jcs/LICENSE` |
| `json-ld-api/` | The expansion and RDF conversion tests of the World Wide Web Consortium (W3C) test suite for JSON-LD 1.1 Processing Algorithms and API | <https://github.com/w3c/json-ld-api> | [W3C Test Suite License and W3C 3-clause BSD License](https://www.w3.org/copyright/test-suites-licenses/), in `json-ld-api/LICENSE.md` |
| `vc-di-eddsa/` | The `eddsa-rdfc-2022` test vector of the W3C Data Integrity EdDSA Cryptosuites v1.0 Recommendation, Appendix B.1, and the two JSON-LD contexts it references | <https://www.w3.org/TR/vc-di-eddsa/> | [W3C Software and Document License](https://www.w3.org/copyright/software-license-2023/), in `vc-di-eddsa/LICENSE` |

[`manifest.json`](manifest.json) records the rest for each set: the commit or the version the files were taken from, the date they were retrieved, which directory of the source they came from, the copyright and license, and one SHA-256 checksum for the whole set.

The W3C Test Suite License allows a suite to be used to claim conformance only as the W3C published it. A changed copy may not be used for that, so the files here are never edited, reformatted, or given a final newline they do not have. `.gitattributes` and `.editorconfig` tell git and editors to leave their bytes alone.

## What each set holds

`contexts/` holds one `.jsonld` file for each context, the text of their license as `LICENSE`, and its own `manifest.json`, which records for each context the URL it is published at, the URL it was retrieved from, the date, the SHA-256 of the file, and the title, copyright, and license of the document it belongs to. `bin/generate-contexts.php` reads that manifest to generate the files under `src/Context/Bundled/`, and a copy of `LICENSE` is kept beside those files, since they are distributed.

`jcs/` holds the `testdata/input` and `testdata/output` directories of the repository as `input/` and `output/`, and its `LICENSE`.

`json-ld-api/` holds these parts of the repository's `tests` directory, under the same names: `expand/`, `toRdf/`, `context.jsonld`, `expand-manifest.jsonld`, `toRdf-manifest.jsonld`, and `LICENSE.md`.

`vc-di-eddsa/` holds four examples of the Recommendation of 15 May 2025, <https://www.w3.org/TR/2025/REC-vc-di-eddsa-20250515/>, and two contexts, with the text of the license as `LICENSE`:

| File | Source |
| --- | --- |
| `credential.jsonld` | Example 8: Credential without Proof |
| `credential.nq` | Example 9: Canonical Credential without Proof |
| `proof-options.jsonld` | Example 11: Proof Options Document |
| `proof-options.nq` | Example 12: Canonical Proof Options Document |
| `contexts/credentials-v2.jsonld` | <https://www.w3.org/ns/credentials/v2> |
| `contexts/credentials-examples-v2.jsonld` | <https://www.w3.org/ns/credentials/examples/v2> |

## Checking the sets

Run this from the root of the repository:

```bash
composer check-fixtures
```

It computes the checksum of each set again and compares it with the one in the manifest. A job in the continuous integration workflow runs the same check on every pull request and push to the main branch.

A checksum that differs from the one in the manifest means that a file has changed since it was copied. If this happens, restore the files from the commit or the version listed in the manifest. Do not record a new checksum unless the set is being updated on purpose, such as a suite moving to a newer commit or a context being added.

## How the checksums are made

Each checksum is one SHA-256 value that covers every file in a set's directory. It changes if the content of any file changes, if a file is renamed, or if a file is added or removed. The checksum of `contexts/` covers `contexts/manifest.json` as well as the contexts, so it changes when an entry in that manifest changes.

It is made in four steps:

1. List every file in the set's directory, by its path from that directory, with `./` in front.
2. Sort the paths byte by byte (`LC_ALL=C`), so that the order is the same on every system.
3. Compute the SHA-256 of each file, which gives one line for each file: the value, two spaces, and the path.
4. Compute the SHA-256 of those lines. That value is the checksum.

`bin/check-fixtures.php` does this in PHP. The same checksum can be made without it:

```bash
(cd resources/json-ld-api && find . -type f | LC_ALL=C sort | xargs shasum -a 256 | shasum -a 256)
```

The command prints the checksum, followed by two spaces and a hyphen.
