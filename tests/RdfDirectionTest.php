<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use SocialWeb\JsonLd\RdfDirection;

class RdfDirectionTest extends TestCase
{
    public function testUsesTheSpecificationValues(): void
    {
        $this->assertSame('i18n-datatype', RdfDirection::I18nDatatype->value);
        $this->assertSame('compound-literal', RdfDirection::CompoundLiteral->value);
        $this->assertCount(2, RdfDirection::cases());
    }
}
