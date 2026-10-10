<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentResumed;
use Shondalai\VidyaLMS\Core\Domain\Events\EntitlementGranted;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class GrantEntitlementTest extends TestCase
{
    private AccessFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = AccessFixture::create();
    }

    public function testManualGrantCreatesEntitlementAndEnrolsTheLearner(): void
    {
        $admin = $this->actingAdmin(10);
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();

        $entitlement = $this->fixture->grantEntitlement->execute($admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);

        self::assertSame(Entitlement::STATUS_ACTIVE, $entitlement->status);
        self::assertSame(10, $entitlement->grantedBy);

        $row = $this->fixture->entitlementRow($entitlement->id());
        self::assertNotNull($row);
        self::assertSame('manual', $row['source']);
        self::assertSame('active', $row['status']);

        $enrolment = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($enrolment);
        self::assertSame('active', $enrolment['status']);
        self::assertSame($this->fixture->env->clock->now()->format('Y-m-d H:i:s'), $enrolment['enrolled_at']);

        self::assertSame(
            ['vidyalms.entitlement.granted', 'vidyalms.enrolment.created'],
            $this->fixture->env->eventBridge->eventNames(),
        );

        $granted = $this->fixture->eventsOfType(EntitlementGranted::class);
        self::assertCount(1, $granted);
        self::assertInstanceOf(EntitlementGranted::class, $granted[0]);
        self::assertSame($entitlement->id(), $granted[0]->entitlementId);
        self::assertSame($learner, $granted[0]->userId);
        self::assertSame($courseId, $granted[0]->courseId);
        self::assertSame('manual', $granted[0]->source);
    }

    public function testSourceRefReplayReturnsExistingEntitlementWithoutSecondEvent(): void
    {
        $system = $this->actingAdmin(1);
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse(['price_mode' => 'paid']);

        $first = $this->fixture->grantEntitlement->execute(
            $system,
            $learner,
            $courseId,
            Entitlement::SOURCE_PURCHASE,
            'order-77',
        );
        $eventsAfterFirst = \count($this->fixture->env->eventBridge->published);

        $second = $this->fixture->grantEntitlement->execute(
            $system,
            $learner,
            $courseId,
            Entitlement::SOURCE_PURCHASE,
            'order-77',
        );

        self::assertSame($first->id(), $second->id());
        self::assertCount($eventsAfterFirst, $this->fixture->env->eventBridge->published);
        self::assertSame(1, $this->fixture->env->database->count(Query::from('entitlements')));
    }

    public function testGuestActorIsDenied(): void
    {
        $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();

        $this->expectException(NotAuthorizedException::class);
        $this->fixture->grantEntitlement->execute(Principal::guest(), 42, $courseId, Entitlement::SOURCE_MANUAL);
    }

    public function testActorWithoutCapabilityIsDenied(): void
    {
        $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();

        $this->expectException(NotAuthorizedException::class);
        $this->fixture->grantEntitlement->execute(Principal::user(9), 42, $courseId, Entitlement::SOURCE_MANUAL);
    }

    public function testUnknownBeneficiaryIsRejected(): void
    {
        $admin = $this->actingAdmin(10);
        $courseId = $this->fixture->createCourse();

        $this->expectException(EntityNotFoundException::class);
        $this->fixture->grantEntitlement->execute($admin, 999, $courseId, Entitlement::SOURCE_MANUAL);
    }

    public function testUnknownCourseIsRejected(): void
    {
        $admin = $this->actingAdmin(10);
        $this->fixture->createUser(42);

        $this->expectException(EntityNotFoundException::class);
        $this->fixture->grantEntitlement->execute($admin, 42, 555, Entitlement::SOURCE_MANUAL);
    }

    public function testArchivedCourseIsRejected(): void
    {
        $admin = $this->actingAdmin(10);
        $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse(['state' => 'archived']);

        $this->expectException(ConflictException::class);
        $this->fixture->grantEntitlement->execute($admin, 42, $courseId, Entitlement::SOURCE_MANUAL);
    }

    public function testOpenSourceIsNotGrantable(): void
    {
        $admin = $this->actingAdmin(10);
        $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();

        $this->expectException(ValidationException::class);
        $this->fixture->grantEntitlement->execute($admin, 42, $courseId, Entitlement::SOURCE_OPEN);
    }

    public function testMalformedExpiryIsRejected(): void
    {
        $admin = $this->actingAdmin(10);
        $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();

        $this->expectException(ValidationException::class);
        $this->fixture->grantEntitlement->execute(
            $admin,
            42,
            $courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            null,
            'next tuesday',
        );
    }

    public function testRegrantAfterRevokeResumesTheSuspendedEnrolment(): void
    {
        $admin = $this->actingAdmin(10);
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();

        $first = $this->fixture->grantEntitlement->execute($admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);
        $this->fixture->revokeEntitlement->execute($admin, $first->id());

        $suspendedRow = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($suspendedRow);
        self::assertSame('suspended', $suspendedRow['status']);

        $second = $this->fixture->grantEntitlement->execute($admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);

        $resumedRow = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($resumedRow);
        self::assertSame('active', $resumedRow['status']);
        self::assertSame($second->id(), (int) $resumedRow['entitlement_id']);

        self::assertCount(1, $this->fixture->eventsOfType(LearnerEnrolled::class));
        self::assertCount(1, $this->fixture->eventsOfType(EnrolmentResumed::class));
    }

    public function testRepeatedManualGrantForTheSameLearnerReturnsTheOneEntitlement(): void
    {
        $admin = $this->actingAdmin(10);
        $learner = $this->fixture->createUser(50);
        $courseId = $this->fixture->createCourse();

        $first = $this->fixture->grantEntitlement->execute($admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);
        $second = $this->fixture->grantEntitlement->execute($admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);
        $third = $this->fixture->grantEntitlement->execute($admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);

        self::assertSame($first->id(), $second->id());
        self::assertSame($first->id(), $third->id());
        self::assertSame(1, $this->fixture->env->database->count(Query::from('entitlements')));
        self::assertCount(1, $this->fixture->eventsOfType(EntitlementGranted::class));
        self::assertCount(1, $this->fixture->eventsOfType(LearnerEnrolled::class));
    }

    public function testRevokingTheOnlyEntitlementSuspendsTheEnrolmentAfterRepeatedGrants(): void
    {
        $admin = $this->actingAdmin(10);
        $learner = $this->fixture->createUser(50);
        $courseId = $this->fixture->createCourse();

        $this->fixture->grantCapability(10, Capability::VIEW_REPORTS);

        $entitlement = $this->fixture->grantEntitlement->execute($admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);
        $this->fixture->grantEntitlement->execute($admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);

        $roster = $this->fixture->listEnrolmentsForCourse->execute($admin, $courseId);
        self::assertCount(1, $roster['items']);
        self::assertSame($entitlement->id(), (int) $roster['items'][0]['entitlement_id']);

        $this->fixture->revokeEntitlement->execute($admin, $entitlement->id());

        $enrolment = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($enrolment);
        self::assertSame('suspended', $enrolment['status']);
    }

    public function testAnExpiredManualEntitlementDoesNotBlockAFreshGrant(): void
    {
        $admin = $this->actingAdmin(10);
        $learner = $this->fixture->createUser(50);
        $courseId = $this->fixture->createCourse();
        $expiresAt = $this->fixture->env->clock->now()->add(new \DateInterval('PT1H'))->format('Y-m-d H:i:s');

        $first = $this->fixture->grantEntitlement->execute(
            $admin,
            $learner,
            $courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            null,
            $expiresAt,
        );

        $this->fixture->env->clock->advance('P1D');
        $this->fixture->expireEntitlements->execute();

        $second = $this->fixture->grantEntitlement->execute($admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);

        self::assertNotSame($first->id(), $second->id());

        $resumed = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($resumed);
        self::assertSame('active', $resumed['status']);
        self::assertSame($second->id(), (int) $resumed['entitlement_id']);
    }

    private function actingAdmin(int $userId): Principal
    {
        $this->fixture->grantCapability($userId);

        return Principal::user($userId);
    }
}
