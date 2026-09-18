<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use SocialWeb\JsonLd\ErrorCode;

use function array_map;
use function array_unique;

class ErrorCodeTest extends TestCase
{
    public function testHasEveryCodeOfTheJsonLd11Api(): void
    {
        $values = array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases());

        $this->assertCount(49, $values);
        $this->assertCount(49, array_unique($values));
    }

    public function testResolvesFromTheSpecificationString(): void
    {
        $this->assertSame(ErrorCode::LoadingRemoteContextFailed, ErrorCode::from('loading remote context failed'));
        $this->assertSame(ErrorCode::IriConfusedWithPrefix, ErrorCode::from('IRI confused with prefix'));
        $this->assertNull(ErrorCode::tryFrom('recursive context inclusion'));
    }
}
