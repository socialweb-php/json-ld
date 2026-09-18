<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Exception;

use InvalidArgumentException;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdException;
use SocialWeb\Test\JsonLd\TestCase;

class InvalidArgumentTest extends TestCase
{
    public function testCanBeCaughtAsAJsonLdException(): void
    {
        $this->expectException(JsonLdException::class);

        throw new InvalidArgument('bad input');
    }

    public function testCanBeCaughtAsAnInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        throw new InvalidArgument('bad input');
    }

    public function testCarriesTheMessage(): void
    {
        $this->assertSame('bad input', (new InvalidArgument('bad input'))->getMessage());
    }
}
