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

use SocialWeb\JsonLd\DocumentReader;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\MalformedJson;
use SocialWeb\JsonLd\Limits;

use function is_array;
use function is_object;
use function sprintf;

use const PHP_INT_MAX;

/**
 * Loads contexts from a pinned set that ships with the library, and from
 * documents the caller adds
 *
 * Nothing is fetched from the network. URLs are matched by exact string
 * comparison, with no normalization and no tolerance for a trailing slash.
 * Each pinned context answers to its `https` URL or to its `http` alias.
 */
final class BundledDocumentLoader implements DocumentLoader
{
    /**
     * The pinned contexts: URL to the name of the file under Bundled/
     */
    private const array PINNED = [
        'https://www.w3.org/ns/activitystreams' => 'activitystreams',
        'http://www.w3.org/ns/activitystreams' => 'activitystreams',
        'https://w3id.org/security/v1' => 'security-v1',
        'http://w3id.org/security/v1' => 'security-v1',
        'https://w3id.org/security/v2' => 'security-v2',
        'http://w3id.org/security/v2' => 'security-v2',
        'https://w3id.org/identity/v1' => 'identity-v1',
        'http://w3id.org/identity/v1' => 'identity-v1',
        'https://w3id.org/security/data-integrity/v1' => 'data-integrity-v1',
        'http://w3id.org/security/data-integrity/v1' => 'data-integrity-v1',
        'https://w3id.org/security/data-integrity/v2' => 'data-integrity-v2',
        'http://w3id.org/security/data-integrity/v2' => 'data-integrity-v2',
        'https://w3id.org/security/multikey/v1' => 'multikey-v1',
        'http://w3id.org/security/multikey/v1' => 'multikey-v1',
        'https://www.w3.org/ns/cid/v1' => 'cid-v1',
        'http://www.w3.org/ns/cid/v1' => 'cid-v1',
        'https://www.w3.org/ns/did/v1' => 'did-v1',
        'http://www.w3.org/ns/did/v1' => 'did-v1',
    ];

    /**
     * Documents the caller added, by URL
     *
     * @var array<string, array<mixed> | object>
     */
    private array $added = [];

    /**
     * Pinned contexts already read from their files, by name
     *
     * @var array<string, array<mixed>>
     */
    private array $pinned = [];

    /**
     * Returns a new loader that also answers for the URL with the document,
     * in place of any pinned context with the same URL
     *
     * The document is not subject to the processor's limits; whoever adds a
     * context vouches for it.
     *
     * @param string $url The URL exactly as documents will reference it
     * @param string | array<mixed> | object $document A JSON-encoded string, a
     *     `stdClass` tree, or an associative array, in which a list is a JSON
     *     array and any other array is a JSON object
     *
     * @throws MalformedJson if the string is not a valid JSON-encoded string
     * @throws InvalidArgument if the document is not a JSON object or array
     */
    public function with(string $url, string | array | object $document): self
    {
        $read = (new DocumentReader(new Limits(PHP_INT_MAX, PHP_INT_MAX)))->read($document);

        if (!is_array($read) && !is_object($read)) {
            throw new InvalidArgument(
                sprintf('The document for "%s" must be a JSON object or a JSON array', $url),
            );
        }

        $loader = clone $this;
        $loader->added[$url] = $read;

        return $loader;
    }

    public function load(string $url): LoadedDocument
    {
        if (isset($this->added[$url])) {
            return new LoadedDocument($url, $this->added[$url]);
        }

        $name = self::PINNED[$url] ?? null;

        if ($name === null) {
            throw new JsonLdError(ErrorCode::LoadingRemoteContextFailed, $url);
        }

        if (!isset($this->pinned[$name])) {
            /** @var array<mixed> $context */
            $context = require __DIR__ . '/Bundled/' . $name . '.php';
            $this->pinned[$name] = $context;
        }

        return new LoadedDocument($url, $this->pinned[$name]);
    }
}
