<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Exception;

use RuntimeException;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\JsonLdErrorCode;
use SocialWeb\JsonLd\Exception\JsonLdException;
use SocialWeb\Test\JsonLd\TestCase;
use UnexpectedValueException;

final class JsonLdErrorTest extends TestCase
{
    public function testCanBeCaughtAsAJsonLdException(): void
    {
        $this->expectException(JsonLdException::class);

        throw new JsonLdError(JsonLdErrorCode::InvalidLocalContext);
    }

    public function testCanBeCaughtAsAnUnexpectedValueException(): void
    {
        $this->expectException(UnexpectedValueException::class);

        throw new JsonLdError(JsonLdErrorCode::InvalidLocalContext);
    }

    public function testMessageIsTheCapitalizedCodeWhenThereIsNoDetail(): void
    {
        $exception = new JsonLdError(JsonLdErrorCode::ContextOverflow);

        $this->assertSame(JsonLdErrorCode::ContextOverflow, $exception->reason);
        $this->assertSame(0, $exception->getCode());
        $this->assertSame('Context overflow', $exception->getMessage());
    }

    public function testMessageKeepsACodeThatStartsWithACapitalAsItIs(): void
    {
        $this->assertSame(
            'IRI confused with prefix',
            (new JsonLdError(JsonLdErrorCode::IriConfusedWithPrefix))->getMessage(),
        );
    }

    public function testMessageNamesTheCodeAndTheDetail(): void
    {
        $exception = new JsonLdError(JsonLdErrorCode::InvalidIriMapping, '"foo"');

        $this->assertSame(JsonLdErrorCode::InvalidIriMapping, $exception->reason);
        $this->assertSame('Invalid IRI mapping: "foo"', $exception->getMessage());
    }

    public function testAcceptsPreviousThrowable(): void
    {
        $previous = new RuntimeException('cause');
        $exception = new JsonLdError(JsonLdErrorCode::LoadingRemoteContextFailed, 'https://example.com/ctx', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
