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
        $this->assertSame(1_000_000, $limits->maxContextOperations);
    }

    public function testAcceptsExplicitValues(): void
    {
        $limits = new Limits(maxDepth: 1, maxValues: PHP_INT_MAX, maxContextOperations: 5);

        $this->assertSame(1, $limits->maxDepth);
        $this->assertSame(PHP_INT_MAX, $limits->maxValues);
        $this->assertSame(5, $limits->maxContextOperations);
    }

    public function testTakesTheLimitsInADocumentedOrder(): void
    {
        $limits = new Limits(2, 3, 4);

        $this->assertSame(2, $limits->maxDepth);
        $this->assertSame(3, $limits->maxValues);
        $this->assertSame(4, $limits->maxContextOperations);
    }

    public function testAcceptsOneAsTheSmallestLimit(): void
    {
        $limits = new Limits(maxDepth: 1, maxValues: 1, maxContextOperations: 1);

        $this->assertSame(1, $limits->maxDepth);
        $this->assertSame(1, $limits->maxValues);
        $this->assertSame(1, $limits->maxContextOperations);
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

    public function testRejectsAContextOperationCountBelowOne(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('maxContextOperations must be at least 1; 0 given');

        new Limits(maxContextOperations: 0);
    }
}
