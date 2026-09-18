<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use SocialWeb\JsonLd\Restrictions;

class RestrictionsTest extends TestCase
{
    public function testRestrictsNothingByDefault(): void
    {
        $restrictions = new Restrictions();

        $this->assertFalse($restrictions->forbidNamedGraphs);
        $this->assertFalse($restrictions->forbidIncludedBlocks);
        $this->assertFalse($restrictions->forbidReverseProperties);
        $this->assertFalse($restrictions->requireSingleTopLevelNode);
    }

    public function testAcceptsEachRestrictionByName(): void
    {
        $restrictions = new Restrictions(forbidIncludedBlocks: true, requireSingleTopLevelNode: true);

        $this->assertFalse($restrictions->forbidNamedGraphs);
        $this->assertTrue($restrictions->forbidIncludedBlocks);
        $this->assertFalse($restrictions->forbidReverseProperties);
        $this->assertTrue($restrictions->requireSingleTopLevelNode);
    }

    public function testAllTurnsEveryRestrictionOn(): void
    {
        $restrictions = Restrictions::all();

        $this->assertTrue($restrictions->forbidNamedGraphs);
        $this->assertTrue($restrictions->forbidIncludedBlocks);
        $this->assertTrue($restrictions->forbidReverseProperties);
        $this->assertTrue($restrictions->requireSingleTopLevelNode);
    }
}
