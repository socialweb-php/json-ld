<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Exception;

use RuntimeException;
use SocialWeb\JsonLd\Exception\JsonLdException;
use SocialWeb\JsonLd\Exception\RestrictedFeature;
use SocialWeb\Test\JsonLd\TestCase;

class RestrictedFeatureTest extends TestCase
{
    public function testCanBeCaughtAsAJsonLdException(): void
    {
        $this->expectException(JsonLdException::class);

        throw new RestrictedFeature('forbidNamedGraphs', 'https://example.com/node');
    }

    public function testCanBeCaughtAsARuntimeException(): void
    {
        $this->expectException(RuntimeException::class);

        throw new RestrictedFeature('forbidNamedGraphs', 'https://example.com/node');
    }

    public function testCarriesTheRestrictionAndDetail(): void
    {
        $exception = new RestrictedFeature('requireSingleTopLevelNode', '2 top-level nodes');

        $this->assertSame('requireSingleTopLevelNode', $exception->restriction);
        $this->assertSame(0, $exception->getCode());
        $this->assertSame('2 top-level nodes', $exception->detail);
        $this->assertSame(
            'The document uses a restricted feature (requireSingleTopLevelNode): 2 top-level nodes',
            $exception->getMessage(),
        );
    }

    public function testAcceptsPreviousThrowable(): void
    {
        $previous = new RuntimeException('cause');
        $exception = new RestrictedFeature('forbidIncludedBlocks', '_:b0', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
