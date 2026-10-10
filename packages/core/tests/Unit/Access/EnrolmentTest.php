<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;

final class EnrolmentTest extends TestCase
{
    public function testStartFactoryCreatesAnActiveUnpersistedEnrolment(): void
    {
        $enrolledAt = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));
        $enrolment = Enrolment::start(42, 7, 3, $enrolledAt);

        self::assertFalse($enrolment->hasId());
        self::assertTrue($enrolment->isActive());
        self::assertSame($enrolledAt, $enrolment->enrolledAt);
        self::assertSame(0.0, $enrolment->progressPercent);
        self::assertNull($enrolment->completedAt);
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Enrolment::start(42, 7, 3, new \DateTimeImmutable())->withStatus('paused');
    }

    public function testNonPositiveEntitlementIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Enrolment::start(42, 7, 0, new \DateTimeImmutable());
    }

    public function testIdBeforePersistenceThrows(): void
    {
        $this->expectException(\LogicException::class);
        Enrolment::start(42, 7, 3, new \DateTimeImmutable())->id();
    }

    public function testResumedWithSwitchesEntitlementAndActivates(): void
    {
        $enrolment = Enrolment::start(42, 7, 3, new \DateTimeImmutable())
            ->withId(9)
            ->withStatus(Enrolment::STATUS_SUSPENDED);

        $resumed = $enrolment->resumedWith(8);

        self::assertSame(9, $resumed->id());
        self::assertTrue($resumed->isActive());
        self::assertSame(8, $resumed->entitlementId);
        self::assertFalse($resumed->isSuspended());
    }

    public function testStatusPredicates(): void
    {
        $enrolment = Enrolment::start(42, 7, 3, new \DateTimeImmutable());

        self::assertTrue($enrolment->withStatus(Enrolment::STATUS_SUSPENDED)->isSuspended());
        self::assertTrue($enrolment->withStatus(Enrolment::STATUS_COMPLETED)->isCompleted());
        self::assertFalse($enrolment->withStatus(Enrolment::STATUS_CANCELLED)->isActive());
    }
}
