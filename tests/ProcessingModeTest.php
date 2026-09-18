<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use SocialWeb\JsonLd\ProcessingMode;

class ProcessingModeTest extends TestCase
{
    public function testUsesTheSpecificationValues(): void
    {
        $this->assertSame('json-ld-1.0', ProcessingMode::JsonLd10->value);
        $this->assertSame('json-ld-1.1', ProcessingMode::JsonLd11->value);
        $this->assertCount(2, ProcessingMode::cases());
    }
}
