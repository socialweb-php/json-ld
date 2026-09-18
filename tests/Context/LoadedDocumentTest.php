<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use SocialWeb\JsonLd\Context\LoadedDocument;
use SocialWeb\Test\JsonLd\TestCase;

class LoadedDocumentTest extends TestCase
{
    public function testCarriesTheDocumentUrlAndTheDocument(): void
    {
        $document = ['@context' => ['name' => 'https://example.com/name']];
        $loaded = new LoadedDocument('https://example.com/context', $document);

        $this->assertSame('https://example.com/context', $loaded->documentUrl);
        $this->assertSame($document, $loaded->document);
    }
}
