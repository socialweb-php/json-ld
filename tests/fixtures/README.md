# Test fixtures

The files in these directories are test data from other projects. They are
kept exactly as those projects published them. `NOTICE`, at the root of this
repository, gives their copyright and licenses. They are not part of the
library, and they are left out of distribution archives.

| Directory | Project | Repository |
| --- | --- | --- |
| `jcs/` | Test data for RFC 8785, the JSON Canonicalization Scheme (JCS) | <https://github.com/cyberphone/json-canonicalization> |
| `json-ld-api/` | The expansion and RDF conversion tests of the World Wide Web Consortium (W3C) test suite for JSON-LD 1.1 Processing Algorithms and API | <https://github.com/w3c/json-ld-api> |

[`manifest.json`](manifest.json) records the rest for each set of fixtures: the
commit the files were taken from, which directories and files of that
repository are kept and where they are kept here, the copyright and license,
and one SHA-256 checksum for the whole set.

## Checking the fixtures

Run this from the root of the repository:

```bash
composer check-fixtures
```

It makes the checksum of each set again and compares it with the one in the
manifest. It also reports any file in a fixture directory that the manifest
does not cover. A job in the continuous integration workflow runs the same
check on every pull request and push to the main branch.

A checksum that differs from the one in the manifest means that a fixture has
changed since it was retrieved. If this happens, restore the files from the
commit hash listed in the manifest. Do not record a new checksum unless the
fixtures are being updated to a newer commit on purpose.

## How the checksums are made

Each checksum is one SHA-256 value that covers a whole set of files. It was made
from the files as they were retrieved from the repository at the commit the
manifest names. It changes if the content of any file changes, if a file is
renamed, or if a file is added to or removed from the paths it covers.

It is made in four steps:

1. List every file under the paths that the manifest gives as `to`, by its path
   from the fixture's own directory, with `./` in front. A path such as `*.nq`
   matches every file of that name in the directory.
2. Sort the paths byte by byte (`LC_ALL=C`), so that the order is the same on
   every system.
3. Compute the SHA-256 of each file, which gives one line for each file: the
   value, two spaces, and the path.
4. Compute the SHA-256 of those lines. That value is the checksum.

`bin/check-fixtures.php` does this in PHP. The same checksum can be made without
it. For `jcs/`:

```bash
(cd tests/fixtures/jcs \
    && find ./input ./output ./LICENSE -type f \
    | LC_ALL=C sort | xargs sha256sum | sha256sum)
```

And for `json-ld-api/`:

```bash
(cd tests/fixtures/json-ld-api \
    && find ./expand ./toRdf ./context.jsonld ./expand-manifest.jsonld \
        ./toRdf-manifest.jsonld ./LICENSE.md -type f \
    | LC_ALL=C sort | xargs sha256sum | sha256sum)
```

Each command prints the checksum, followed by two spaces and a hyphen.
