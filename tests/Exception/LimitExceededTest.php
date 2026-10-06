<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Exception;

use RuntimeException;
use SocialWeb\JsonLd\Exception\JsonLdException;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\Test\JsonLd\TestCase;

class LimitExceededTest extends TestCase
{
    public function testCanBeCaughtAsAJsonLdException(): void
    {
        $this->expectException(JsonLdException::class);

        throw new LimitExceeded('maxDepth', 128);
    }

    public function testCanBeCaughtAsARuntimeException(): void
    {
        $this->expectException(RuntimeException::class);

        throw new LimitExceeded('maxDepth', 128);
    }

    public function testCarriesTheLimitAndValue(): void
    {
        $exception = new LimitExceeded('maxContextOperations', 1_000_000);

        $this->assertSame('maxContextOperations', $exception->limit);
        $this->assertSame(0, $exception->getCode());
        $this->assertSame(1_000_000, $exception->value);
        $this->assertSame('The maxContextOperations limit of 1000000 was exceeded', $exception->getMessage());
    }

    public function testAcceptsPreviousThrowable(): void
    {
        $previous = new RuntimeException('cause');
        $exception = new LimitExceeded('maxDepth', 128, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
