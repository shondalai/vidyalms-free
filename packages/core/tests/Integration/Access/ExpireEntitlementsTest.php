<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentSuspended;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class ExpireEntitlementsTest extends TestCase
{
    private AccessFixture $fixture;

    private Principal $admin;

    protected function setUp(): void
    {
        $this->fixture = AccessFixture::create();
        $this->fixture->grantCapability(10);
        $this->admin = Principal::user(10);
    }

    public function testDueEntitlementExpiresAndSuspendsTheEnrolmentKeepingProgress(): void
    {
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();
        $expiresAt = $this->fixture->env->clock->now()->add(new \DateInterval('P1D'))->format('Y-m-d H:i:s');

        $entitlement = $this->fixture->grantEntitlement->execute(
            $this->admin,
            $learner,
            $courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            null,
            $expiresAt,
        );

        $enrolmentRow = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($enrolmentRow);
        $progressId = $this->fixture->insertLessonProgress((int) $enrolmentRow['id'], 5, $learner);

        $this->fixture->env->clock->advance('P2D');
        $counts = $this->fixture->expireEntitlements->execute();

        self::assertSame(['expired' => 1, 'suspended' => 1, 'activated' => 0], $counts);

        $entitlementRow = $this->fixture->entitlementRow($entitlement->id());
        self::assertNotNull($entitlementRow);
        self::assertSame('expired', $entitlementRow['status']);

        $suspendedRow = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($suspendedRow);
        self::assertSame('suspended', $suspendedRow['status']);

        $progress = $this->fixture->env->database->selectOne(Query::from('lesson_progress')->where('id', '=', $progressId));
        self::assertNotNull($progress);
        self::assertEquals(40, $progress['progress_percent']);
        self::assertEquals(120, $progress['seconds_spent']);

        self::assertCount(1, $this->fixture->eventsOfType(EnrolmentSuspended::class));
    }

    public function testSecondRunChangesNothing(): void
    {
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();
        $expiresAt = $this->fixture->env->clock->now()->add(new \DateInterval('PT1H'))->format('Y-m-d H:i:s');

        $this->fixture->grantEntitlement->execute(
            $this->admin,
            $learner,
            $courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            null,
            $expiresAt,
        );

        $this->fixture->env->clock->advance('P1D');
        $first = $this->fixture->expireEntitlements->execute();
        $eventsAfterFirst = \count($this->fixture->env->eventBridge->published);

        $second = $this->fixture->expireEntitlements->execute();

        self::assertSame(['expired' => 1, 'suspended' => 1, 'activated' => 0], $first);
        self::assertSame(['expired' => 0, 'suspended' => 0, 'activated' => 0], $second);
        self::assertCount($eventsAfterFirst, $this->fixture->env->eventBridge->published);
        self::assertCount(1, $this->fixture->eventsOfType(EnrolmentSuspended::class));
    }

    public function testEnrolmentSurvivesWhenAnotherEntitlementStillCoversTheCourse(): void
    {
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();
        $expiresAt = $this->fixture->env->clock->now()->add(new \DateInterval('PT1H'))->format('Y-m-d H:i:s');

        $this->fixture->grantEntitlement->execute(
            $this->admin,
            $learner,
            $courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            null,
            $expiresAt,
        );
        $this->fixture->grantEntitlement->execute($this->admin, $learner, $courseId, Entitlement::SOURCE_PURCHASE, 'order-1');

        $this->fixture->env->clock->advance('P1D');
        $counts = $this->fixture->expireEntitlements->execute();

        self::assertSame(['expired' => 1, 'suspended' => 0, 'activated' => 0], $counts);

        $enrolmentRow = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($enrolmentRow);
        self::assertSame('active', $enrolmentRow['status']);
        self::assertCount(0, $this->fixture->eventsOfType(EnrolmentSuspended::class));
    }

    public function testAnExtendCommittedBetweenTheReadAndTheWriteIsNotOverwritten(): void
    {
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();
        $expiresAt = $this->fixture->env->clock->now()->add(new \DateInterval('PT1H'))->format('Y-m-d H:i:s');

        $entitlement = $this->fixture->grantEntitlement->execute(
            $this->admin,
            $learner,
            $courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            null,
            $expiresAt,
        );

        $this->fixture->env->clock->advance('P1D');

        $interleaving = new InterleavingEntitlementRepository($this->fixture->entitlements);
        $interleaving->interleaveAfterDueRead(function () use ($entitlement): void {
            $this->fixture->extendEntitlement->execute($this->admin, $entitlement->id(), '2030-01-01 00:00:00');
        });

        $counts = $this->fixture->expireEntitlementsOver($interleaving)->execute();

        self::assertSame(['expired' => 0, 'suspended' => 0, 'activated' => 0], $counts);

        $row = $this->fixture->entitlementRow($entitlement->id());
        self::assertNotNull($row);
        self::assertSame('active', $row['status']);
        self::assertSame('2030-01-01 00:00:00', $row['expires_at']);

        $enrolment = $this->fixture->enrolmentRow($learner, $courseId);
        self::assertNotNull($enrolment);
        self::assertSame('active', $enrolment['status']);
        self::assertCount(0, $this->fixture->eventsOfType(EnrolmentSuspended::class));
    }

    public function testARevokeCommittedBetweenTheReadAndTheWriteIsNotOverwritten(): void
    {
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();
        $expiresAt = $this->fixture->env->clock->now()->add(new \DateInterval('PT1H'))->format('Y-m-d H:i:s');

        $entitlement = $this->fixture->grantEntitlement->execute(
            $this->admin,
            $learner,
            $courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            null,
            $expiresAt,
        );

        $this->fixture->env->clock->advance('P1D');

        $interleaving = new InterleavingEntitlementRepository($this->fixture->entitlements);
        $interleaving->interleaveAfterDueRead(function () use ($entitlement): void {
            $this->fixture->revokeEntitlement->execute($this->admin, $entitlement->id());
        });

        $counts = $this->fixture->expireEntitlementsOver($interleaving)->execute();

        self::assertSame(0, $counts['expired']);

        $row = $this->fixture->entitlementRow($entitlement->id());
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);
    }

    public function testEntitlementWithoutExpiryIsUntouched(): void
    {
        $learner = $this->fixture->createUser(42);
        $courseId = $this->fixture->createCourse();
        $entitlement = $this->fixture->grantEntitlement->execute($this->admin, $learner, $courseId, Entitlement::SOURCE_MANUAL);

        $this->fixture->env->clock->advance('P30D');
        $counts = $this->fixture->expireEntitlements->execute();

        self::assertSame(['expired' => 0, 'suspended' => 0, 'activated' => 0], $counts);

        $entitlementRow = $this->fixture->entitlementRow($entitlement->id());
        self::assertNotNull($entitlementRow);
        self::assertSame('active', $entitlementRow['status']);
    }
}
