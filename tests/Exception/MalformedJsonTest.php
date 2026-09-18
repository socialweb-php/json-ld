<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Exception;

use RuntimeException;
use SocialWeb\JsonLd\Exception\JsonLdException;
use SocialWeb\JsonLd\Exception\MalformedJson;
use SocialWeb\Test\JsonLd\TestCase;

use const JSON_ERROR_SYNTAX;

class MalformedJsonTest extends TestCase
{
    public function testCanBeCaughtAsAJsonLdException(): void
    {
        $this->expectException(JsonLdException::class);

        throw new MalformedJson(JSON_ERROR_SYNTAX, 'Syntax error');
    }

    public function testCanBeCaughtAsARuntimeException(): void
    {
        $this->expectException(RuntimeException::class);

        throw new MalformedJson(JSON_ERROR_SYNTAX, 'Syntax error');
    }

    public function testCarriesTheErrorCodeAndReason(): void
    {
        $exception = new MalformedJson(JSON_ERROR_SYNTAX, 'Syntax error');

        $this->assertSame(JSON_ERROR_SYNTAX, $exception->jsonError);
        $this->assertSame(0, $exception->getCode());
        $this->assertSame('Malformed JSON: Syntax error', $exception->getMessage());
    }

    public function testAcceptsPreviousThrowable(): void
    {
        $previous = new RuntimeException('cause');
        $exception = new MalformedJson(0, 'a number is not finite', $previous);

        $this->assertSame(0, $exception->jsonError);
        $this->assertSame($previous, $exception->getPrevious());
    }
}
