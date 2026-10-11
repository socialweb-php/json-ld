<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Rdf;

use SocialWeb\JsonLd\Rdf\BlankNodeIssuer;
use SocialWeb\Test\JsonLd\TestCase;

class BlankNodeIssuerTest extends TestCase
{
    public function testIssuesNewIdentifiersInOrder(): void
    {
        $issuer = new BlankNodeIssuer();

        $this->assertSame('_:b0', $issuer->issue());
        $this->assertSame('_:b1', $issuer->issue());
        $this->assertSame('_:b2', $issuer->issue(null));
    }

    public function testRelabelsAnIdentifierOnceAndKeepsTheLabel(): void
    {
        $issuer = new BlankNodeIssuer();

        $this->assertSame('_:b0', $issuer->issue('_:a'));
        $this->assertSame('_:b1', $issuer->issue('_:b'));
        $this->assertSame('_:b0', $issuer->issue('_:a'));
        $this->assertSame('_:b1', $issuer->issue('_:b'));
    }

    public function testANewIdentifierNeverCollidesWithARelabeledOne(): void
    {
        $issuer = new BlankNodeIssuer();

        // The document's own `_:b1` is relabeled before the issuer reaches the
        // count of 1, and the count moves past the label it gave.
        $this->assertSame('_:b0', $issuer->issue('_:b1'));
        $this->assertSame('_:b1', $issuer->issue());
        $this->assertSame('_:b0', $issuer->issue('_:b1'));
        $this->assertSame('_:b2', $issuer->issue('_:b0'));
    }

    public function testEachIssuerCountsForItself(): void
    {
        $first = new BlankNodeIssuer();
        $second = new BlankNodeIssuer();

        $first->issue('_:a');
        $first->issue();

        $this->assertSame('_:b0', $second->issue('_:a'));
        $this->assertSame('_:b2', $first->issue('_:c'));
    }
}
