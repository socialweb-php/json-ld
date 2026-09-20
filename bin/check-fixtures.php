<?php

/**
 * Checks the test fixtures under tests/fixtures/ against the SHA-256 checksums
 * in tests/fixtures/manifest.json.
 *
 * Usage: php bin/check-fixtures.php [fixtures-directory]
 *
 * For each set of fixtures, the script lists every file under the paths the
 * manifest names, sorts the paths byte by byte, computes the SHA-256 of each
 * file, and computes the SHA-256 of the resulting lines: the value, two
 * spaces, and the path with "./" in front. That is the method that
 * tests/fixtures/README.md describes, and it gives the same value as the shell
 * commands there. A path may end in a pattern such as "*.nq", which matches
 * every file of that name in the directory. The script also reports any file in
 * a set's directory that the manifest's paths do not cover.
 *
 * It exits with 0 when every set matches and 1 when any does not.
 *
 * ---
 *
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

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");

    exit(1);
};

/**
 * Returns every file under a path, as paths from the base directory with "./"
 * in front
 *
 * @return list<string>
 */
$filesUnder = static function (string $base, string $path) use ($fail): array {
    $files = [];
    $pending = [$path];

    while ($pending !== []) {
        $current = array_pop($pending);
        $full = $base . '/' . $current;

        if (is_file($full)) {
            $files[] = './' . $current;

            continue;
        }

        $entries = scandir($full);

        if ($entries === false) {
            $fail(sprintf('Cannot read the directory %s', $full));
        }

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $pending[] = $current . '/' . $entry;
            }
        }
    }

    return $files;
};

/**
 * Returns every file that a pattern such as "*.nq" matches, as paths from the
 * base directory with "./" in front
 *
 * @return list<string>
 */
$filesMatching = static function (string $base, string $pattern): array {
    $files = [];

    foreach (glob($base . '/' . $pattern) ?: [] as $match) {
        if (is_file($match)) {
            $files[] = './' . substr($match, strlen($base) + 1);
        }
    }

    return $files;
};

/**
 * Returns every file in a directory, as paths from it with "./" in front
 *
 * @return list<string>
 */
$filesIn = static function (string $directory) use ($fail, $filesUnder): array {
    $entries = scandir($directory);

    if ($entries === false) {
        $fail(sprintf('Cannot read the directory %s', $directory));
    }

    $files = [];

    foreach ($entries as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $files = [...$files, ...$filesUnder($directory, $entry)];
        }
    }

    return $files;
};

$fixturesDirectory = rtrim($argv[1] ?? __DIR__ . '/../tests/fixtures', '/');
$manifestFile = $fixturesDirectory . '/manifest.json';
$json = @file_get_contents($manifestFile);

if ($json === false) {
    $fail(sprintf('Cannot read %s', $manifestFile));
}

/**
 * @var array{
 *     fixtures: list<array{
 *         name: string,
 *         directory: string,
 *         paths: list<array{from: string, to: string}>,
 *         sha256: string,
 *     }>,
 * } $manifest
 */
$manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$failed = false;

foreach ($manifest['fixtures'] as $fixture) {
    $base = $fixturesDirectory . '/' . $fixture['directory'];
    $files = [];

    foreach ($fixture['paths'] as $path) {
        $found = str_contains($path['to'], '*')
            ? $filesMatching($base, $path['to'])
            : (file_exists($base . '/' . $path['to']) ? $filesUnder($base, $path['to']) : []);

        if ($found === []) {
            $fail(sprintf('%s: %s is in the manifest and not in %s', $fixture['name'], $path['to'], $base));
        }

        $files = [...$files, ...$found];
    }

    sort($files, SORT_STRING);

    $lines = '';

    foreach ($files as $file) {
        $lines .= hash_file('sha256', $base . '/' . substr($file, 2)) . '  ' . $file . "\n";
    }

    $checksum = hash('sha256', $lines);
    $notCovered = array_diff($filesIn($base), $files);

    if ($checksum === $fixture['sha256'] && $notCovered === []) {
        printf("%s: OK (%d files, %s)\n", $fixture['name'], count($files), $checksum);

        continue;
    }

    $failed = true;

    if ($checksum !== $fixture['sha256']) {
        printf(
            "%s: the checksum does not match\n  expected %s\n  computed %s\n",
            $fixture['name'],
            $fixture['sha256'],
            $checksum,
        );
    }

    foreach ($notCovered as $file) {
        printf("%s: %s is not covered by the manifest\n", $fixture['name'], $file);
    }
}

exit($failed ? 1 : 0);
