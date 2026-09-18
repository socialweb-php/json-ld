<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use SocialWeb\JsonLd\DataLossCondition;

use function array_map;
use function array_unique;

class DataLossConditionTest extends TestCase
{
    public function testHasFifteenDistinctConditions(): void
    {
        $values = array_map(static fn (DataLossCondition $c): string => $c->value, DataLossCondition::cases());

        $this->assertCount(15, $values);
        $this->assertCount(15, array_unique($values));
    }

    public function testResolvesFromItsString(): void
    {
        $this->assertSame(DataLossCondition::DroppedDirection, DataLossCondition::from('dropped direction'));
        $this->assertSame(
            DataLossCondition::MalformedBlankNodeIdentifier,
            DataLossCondition::from('malformed blank node identifier'),
        );
    }
}
