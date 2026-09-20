<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

use SocialWeb\JsonLd\Context\DocumentLoader;
use SocialWeb\JsonLd\Context\LoadedDocument;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\JsonLdError;
use stdClass;

use function is_array;
use function is_file;
use function json_decode;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Supplies the documents of the vendored W3C suite by the URL the suite is
 * published at
 */
final class FixtureDocumentLoader implements DocumentLoader
{
    public function load(string $url): LoadedDocument
    {
        $path = str_starts_with($url, W3cManifest::BASE_IRI) ? substr($url, strlen(W3cManifest::BASE_IRI)) : '..';
        $file = W3cManifest::FIXTURES . '/' . $path;

        if (str_contains($path, '..') || !is_file($file)) {
            throw new JsonLdError(ErrorCode::LoadingRemoteContextFailed, $url);
        }

        $document = json_decode(W3cManifest::read($file), false, 512, JSON_THROW_ON_ERROR);

        if (!is_array($document) && !$document instanceof stdClass) {
            throw new JsonLdError(ErrorCode::LoadingRemoteContextFailed, $url);
        }

        return new LoadedDocument($url, $document);
    }
}
