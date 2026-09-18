<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Exception;

use RuntimeException;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\JsonLdException;
use SocialWeb\Test\JsonLd\TestCase;

class JsonLdErrorTest extends TestCase
{
    public function testCanBeCaughtAsAJsonLdException(): void
    {
        $this->expectException(JsonLdException::class);

        throw new JsonLdError(ErrorCode::InvalidLocalContext);
    }

    public function testCanBeCaughtAsARuntimeException(): void
    {
        $this->expectException(RuntimeException::class);

        throw new JsonLdError(ErrorCode::InvalidLocalContext);
    }

    public function testMessageIsTheCodeWhenThereIsNoDetail(): void
    {
        $exception = new JsonLdError(ErrorCode::ContextOverflow);

        $this->assertSame(ErrorCode::ContextOverflow, $exception->errorCode);
        $this->assertSame(0, $exception->getCode());
        $this->assertSame('context overflow', $exception->getMessage());
    }

    public function testMessageNamesTheCodeAndTheDetail(): void
    {
        $exception = new JsonLdError(ErrorCode::InvalidIriMapping, 'term "foo"');

        $this->assertSame(ErrorCode::InvalidIriMapping, $exception->errorCode);
        $this->assertSame('invalid IRI mapping: term "foo"', $exception->getMessage());
    }

    public function testAcceptsPreviousThrowable(): void
    {
        $previous = new RuntimeException('cause');
        $exception = new JsonLdError(ErrorCode::LoadingRemoteContextFailed, 'https://example.com/ctx', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
