<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Exception;

use RuntimeException;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\DataLossReason;
use SocialWeb\JsonLd\Exception\JsonLdException;
use SocialWeb\Test\JsonLd\TestCase;

use function str_repeat;
use function strlen;

final class DataLossTest extends TestCase
{
    public function testCanBeCaughtAsAJsonLdException(): void
    {
        $this->expectException(JsonLdException::class);

        throw new DataLoss(DataLossReason::UndefinedProperty, 'foo');
    }

    public function testCanBeCaughtAsARuntimeException(): void
    {
        $this->expectException(RuntimeException::class);

        throw new DataLoss(DataLossReason::UndefinedProperty, 'foo');
    }

    public function testHoldsTheReasonAndDetail(): void
    {
        $exception = new DataLoss(DataLossReason::BlankNodePredicate, '_:b0');

        $this->assertSame(DataLossReason::BlankNodePredicate, $exception->reason);
        $this->assertSame(0, $exception->getCode());
        $this->assertSame('_:b0', $exception->detail);
        $this->assertSame('Data loss detected (BlankNodePredicate): "_:b0"', $exception->getMessage());
    }

    public function testTheMessageQuotesAShortDetailWhole(): void
    {
        $detail = str_repeat('a', DataLoss::MESSAGE_DETAIL_LENGTH);
        $exception = new DataLoss(DataLossReason::FreeFloatingNode, $detail);

        $this->assertSame('Data loss detected (FreeFloatingNode): "' . $detail . '"', $exception->getMessage());

        $wide = str_repeat('é', DataLoss::MESSAGE_DETAIL_LENGTH);
        $wideException = new DataLoss(DataLossReason::FreeFloatingNode, $wide);

        $this->assertSame('Data loss detected (FreeFloatingNode): "' . $wide . '"', $wideException->getMessage());
    }

    public function testTheMessageCutsALongDetailAndCountsItsCharacters(): void
    {
        $detail = str_repeat('é', DataLoss::MESSAGE_DETAIL_LENGTH) . 'xyz';
        $exception = new DataLoss(DataLossReason::FreeFloatingNode, $detail);

        $this->assertSame($detail, $exception->detail);
        $this->assertSame(
            'Data loss detected (FreeFloatingNode): "' . str_repeat('é', DataLoss::MESSAGE_DETAIL_LENGTH)
                . '" (first 256 of 259 characters)',
            $exception->getMessage(),
        );

        $oneOver = str_repeat('a', DataLoss::MESSAGE_DETAIL_LENGTH + 1);

        $this->assertSame(
            'Data loss detected (FreeFloatingNode): "' . str_repeat('a', DataLoss::MESSAGE_DETAIL_LENGTH)
                . '" (first 256 of 257 characters)',
            (new DataLoss(DataLossReason::FreeFloatingNode, $oneOver))->getMessage(),
        );
    }

    public function testTheMessageCutsADetailThatIsNotValidUtf8ByBytes(): void
    {
        $detail = "\xff" . str_repeat('a', DataLoss::MESSAGE_DETAIL_LENGTH + 3);
        $exception = new DataLoss(DataLossReason::RelativeSubject, $detail);

        $this->assertSame(
            'Data loss detected (RelativeSubject): "' . "\xff" . str_repeat('a', DataLoss::MESSAGE_DETAIL_LENGTH - 1)
                . '" (first 256 of 260 bytes)',
            $exception->getMessage(),
        );
        $this->assertSame(DataLoss::MESSAGE_DETAIL_LENGTH + 4, strlen($detail));

        $whole = "\xff" . str_repeat('a', DataLoss::MESSAGE_DETAIL_LENGTH - 1);

        $this->assertSame(
            'Data loss detected (RelativeSubject): "' . $whole . '"',
            (new DataLoss(DataLossReason::RelativeSubject, $whole))->getMessage(),
        );
    }

    public function testAcceptsPreviousThrowable(): void
    {
        $previous = new RuntimeException('cause');
        $exception = new DataLoss(DataLossReason::RelativeSubject, 'relative/path', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
