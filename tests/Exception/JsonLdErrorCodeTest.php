<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Exception;

use SocialWeb\JsonLd\Exception\JsonLdErrorCode;
use SocialWeb\Test\JsonLd\TestCase;

use function array_map;
use function array_unique;

final class JsonLdErrorCodeTest extends TestCase
{
    public function testHasEveryCodeOfTheJsonLd11Api(): void
    {
        $values = array_map(static fn (JsonLdErrorCode $code): string => $code->value, JsonLdErrorCode::cases());

        $this->assertCount(49, $values);
        $this->assertCount(49, array_unique($values));
    }

    public function testResolvesFromTheSpecificationString(): void
    {
        $this->assertSame(
            JsonLdErrorCode::LoadingRemoteContextFailed,
            JsonLdErrorCode::from('loading remote context failed'),
        );
        $this->assertSame(JsonLdErrorCode::IriConfusedWithPrefix, JsonLdErrorCode::from('IRI confused with prefix'));
        $this->assertNull(JsonLdErrorCode::tryFrom('recursive context inclusion'));
    }
}
