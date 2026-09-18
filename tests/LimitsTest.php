<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Limits;

use const PHP_INT_MAX;

class LimitsTest extends TestCase
{
    public function testHasTheDocumentedDefaults(): void
    {
        $limits = new Limits();

        $this->assertSame(128, $limits->maxDepth);
        $this->assertSame(100_000, $limits->maxValues);
    }

    public function testAcceptsExplicitValues(): void
    {
        $limits = new Limits(maxDepth: 1, maxValues: PHP_INT_MAX);

        $this->assertSame(1, $limits->maxDepth);
        $this->assertSame(PHP_INT_MAX, $limits->maxValues);
    }

    public function testAcceptsOneAsTheSmallestLimit(): void
    {
        $limits = new Limits(maxDepth: 1, maxValues: 1);

        $this->assertSame(1, $limits->maxDepth);
        $this->assertSame(1, $limits->maxValues);
    }

    public function testRejectsADepthBelowOne(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('maxDepth must be at least 1; 0 given');

        new Limits(maxDepth: 0);
    }

    public function testRejectsAValueCountBelowOne(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('maxValues must be at least 1; -5 given');

        new Limits(maxValues: -5);
    }
}
