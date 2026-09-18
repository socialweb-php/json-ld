<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Exception;

use RuntimeException;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\JsonLdException;
use SocialWeb\Test\JsonLd\TestCase;

class DataLossTest extends TestCase
{
    public function testCanBeCaughtAsAJsonLdException(): void
    {
        $this->expectException(JsonLdException::class);

        throw new DataLoss(DataLossCondition::UndefinedProperty, 'foo');
    }

    public function testCanBeCaughtAsARuntimeException(): void
    {
        $this->expectException(RuntimeException::class);

        throw new DataLoss(DataLossCondition::UndefinedProperty, 'foo');
    }

    public function testCarriesTheConditionAndDetail(): void
    {
        $exception = new DataLoss(DataLossCondition::BlankNodePredicate, '_:b0');

        $this->assertSame(DataLossCondition::BlankNodePredicate, $exception->condition);
        $this->assertSame(0, $exception->getCode());
        $this->assertSame('_:b0', $exception->detail);
        $this->assertSame('Data loss detected (blank node predicate): _:b0', $exception->getMessage());
    }

    public function testAcceptsPreviousThrowable(): void
    {
        $previous = new RuntimeException('cause');
        $exception = new DataLoss(DataLossCondition::RelativeSubject, 'relative/path', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
