<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Exception;

use SocialWeb\JsonLd\Exception\DataLossReason;
use SocialWeb\Test\JsonLd\TestCase;

use function array_map;
use function array_unique;

final class DataLossReasonTest extends TestCase
{
    public function testHasFifteenDistinctReasons(): void
    {
        $names = array_map(static fn (DataLossReason $reason): string => $reason->name, DataLossReason::cases());

        $this->assertCount(15, $names);
        $this->assertCount(15, array_unique($names));
    }
}
