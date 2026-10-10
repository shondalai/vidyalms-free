<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentSuspended;
use Shondalai\VidyaLMS\Core\Domain\Events\EntitlementRevoked;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class RevokeEntitlementTest extends TestCase
{
    private AccessFixture $fixture;

    private Principal $admin;

    protected function setUp(): void
    {
        $this->fixture = AccessFixture::create();
        $this->fixture->grantCapability(10);
        $this->admin = Principal::user(10);
    }

    public function testRevokingTheLastEntitlementSuspendsTheEnrolmentAndKeepsProgress(): void
    {
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();
        $entitlement = $this->fixture->grantEntitlement->execute($this->admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);

        $enrolmentRow = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($enrolmentRow);
        $progressId = $this->fixture->insertLessonProgress((int) $enrolmentRow['id'], 5, $learner);

        $revoked = $this->fixture->revokeEntitlement->execute($this->admin, $entitlement->id(), 'refund');

        self::assertSame(Entitlement::STATUS_REVOKED, $revoked->status);

        $entitlementRow = $this->fixture->entitlementRow($entitlement->id());
        self::assertNotNull($entitlementRow);
        self::assertSame('revoked', $entitlementRow['status']);

        $suspendedRow = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($suspendedRow);
        self::assertSame('suspended', $suspendedRow['status']);

        $progress = $this->fixture->env->database->selectOne(Query::from('lesson_progress')->where('id', '=', $progressId));
        self::assertNotNull($progress);
        self::assertEquals(40, $progress['progress_percent']);

        $revokedEvents = $this->fixture->eventsOfType(EntitlementRevoked::class);
        self::assertCount(1, $revokedEvents);
        self::assertInstanceOf(EntitlementRevoked::class, $revokedEvents[0]);
        self::assertSame('refund', $revokedEvents[0]->reason);
        self::assertCount(1, $this->fixture->eventsOfType(EnrolmentSuspended::class));
    }

    public function testRevokingOneOfTwoEntitlementsKeepsTheEnrolmentActive(): void
    {
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();
        $manual = $this->fixture->grantEntitlement->execute($this->admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);
        $this->fixture->grantEntitlement->execute($this->admin, $learner, $courseId, Entitlement::SOURCE_PURCHASE, 'order-9');

        $this->fixture->revokeEntitlement->execute($this->admin, $manual->id());

        $enrolmentRow = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($enrolmentRow);
        self::assertSame('active', $enrolmentRow['status']);
        self::assertCount(0, $this->fixture->eventsOfType(EnrolmentSuspended::class));
        self::assertCount(1, $this->fixture->eventsOfType(EntitlementRevoked::class));
    }

    public function testRevokeRequiresTheEnrolOthersCapability(): void
    {
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();
        $entitlement = $this->fixture->grantEntitlement->execute($this->admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);

        $this->expectException(NotAuthorizedException::class);
        $this->fixture->revokeEntitlement->execute(Principal::user(42), $entitlement->id());
    }

    public function testRevokingTwiceChangesNothingAndEmitsNothingNew(): void
    {
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();
        $entitlement = $this->fixture->grantEntitlement->execute($this->admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);

        $this->fixture->revokeEntitlement->execute($this->admin, $entitlement->id());
        $eventsAfterFirst = \count($this->fixture->env->eventBridge->published);

        $again = $this->fixture->revokeEntitlement->execute($this->admin, $entitlement->id());

        self::assertSame(Entitlement::STATUS_REVOKED, $again->status);
        self::assertCount($eventsAfterFirst, $this->fixture->env->eventBridge->published);
    }

    public function testRevokingAnUnknownEntitlementFails(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->fixture->revokeEntitlement->execute($this->admin, 999);
    }
}
