<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;

final class EntitlementTest extends TestCase
{
    public function testGrantFactoryStartsActiveWithoutAnId(): void
    {
        $entitlement = Entitlement::grant(42, 7, Entitlement::SOURCE_MANUAL, 'ref-1', 10);

        self::assertFalse($entitlement->hasId());
        self::assertSame(Entitlement::STATUS_ACTIVE, $entitlement->status);
        self::assertTrue($entitlement->isActive());
        self::assertSame('ref-1', $entitlement->sourceRef);
        self::assertSame(10, $entitlement->grantedBy);
    }

    public function testUnknownSourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Entitlement::grant(42, 7, 'gift');
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Entitlement::grant(42, 7, Entitlement::SOURCE_MANUAL)->withStatus('paused');
    }

    public function testNonPositiveUserIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Entitlement::grant(0, 7, Entitlement::SOURCE_MANUAL);
    }

    public function testIdBeforePersistenceThrows(): void
    {
        $this->expectException(\LogicException::class);
        Entitlement::grant(42, 7, Entitlement::SOURCE_MANUAL)->id();
    }

    public function testWithIdKeepsEveryOtherField(): void
    {
        $entitlement = Entitlement::grant(42, 7, Entitlement::SOURCE_SEAT, 'seat-3')->withId(11);

        self::assertSame(11, $entitlement->id());
        self::assertSame(42, $entitlement->userId);
        self::assertSame(7, $entitlement->courseId);
        self::assertSame(Entitlement::SOURCE_SEAT, $entitlement->source);
        self::assertSame('seat-3', $entitlement->sourceRef);
    }

    public function testIsExpiredAtTreatsTheBoundaryAsExpired(): void
    {
        $expiry = new \DateTimeImmutable('2026-02-01 00:00:00', new \DateTimeZone('UTC'));
        $entitlement = new Entitlement(
            1,
            42,
            7,
            Entitlement::SOURCE_MANUAL,
            null,
            Entitlement::STATUS_ACTIVE,
            null,
            null,
            $expiry,
        );

        self::assertFalse($entitlement->isExpiredAt($expiry->sub(new \DateInterval('PT1S'))));
        self::assertTrue($entitlement->isExpiredAt($expiry));
        self::assertTrue($entitlement->isExpiredAt($expiry->add(new \DateInterval('PT1S'))));
    }

    public function testEntitlementWithoutExpiryNeverExpires(): void
    {
        $entitlement = Entitlement::grant(42, 7, Entitlement::SOURCE_MANUAL);

        self::assertFalse($entitlement->isExpiredAt(new \DateTimeImmutable('2100-01-01', new \DateTimeZone('UTC'))));
    }

    public function testRevokedEntitlementIsNotExpiredAtAnyMoment(): void
    {
        $expiry = new \DateTimeImmutable('2026-02-01 00:00:00', new \DateTimeZone('UTC'));
        $entitlement = Entitlement::grant(42, 7, Entitlement::SOURCE_MANUAL, null, null, null, $expiry)
            ->withStatus(Entitlement::STATUS_REVOKED);

        self::assertFalse($entitlement->isActive());
        self::assertFalse($entitlement->isExpiredAt($expiry->add(new \DateInterval('P1D'))));
    }
}
