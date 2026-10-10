<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentResumed;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class EnrolLearnerTest extends TestCase
{
    private AccessFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = AccessFixture::create();
    }

    public function testFirstEnrolmentInsertsActiveRowAndEmitsLearnerEnrolled(): void
    {
        $courseId = $this->fixture->createCourse();
        $entitlement = $this->fixture->entitlements->add(Entitlement::grant(42, $courseId, Entitlement::SOURCE_MANUAL));

        $enrolment = $this->fixture->enrolLearner->execute(42, $courseId, $entitlement->id());

        self::assertSame(Enrolment::STATUS_ACTIVE, $enrolment->status);

        $row = $this->fixture->enrolmentRow(42, $courseId);
        self::assertNotNull($row);
        self::assertSame('active', $row['status']);
        self::assertSame($entitlement->id(), (int) $row['entitlement_id']);
        self::assertSame($this->fixture->env->clock->now()->format('Y-m-d H:i:s'), $row['enrolled_at']);

        $events = $this->fixture->eventsOfType(LearnerEnrolled::class);
        self::assertCount(1, $events);
        self::assertInstanceOf(LearnerEnrolled::class, $events[0]);
        self::assertSame($enrolment->id(), $events[0]->enrolmentId);
        self::assertSame($entitlement->id(), $events[0]->entitlementId);
    }

    public function testEnrollingTwiceReturnsTheSameEnrolmentWithoutDuplicateEvent(): void
    {
        $courseId = $this->fixture->createCourse();
        $entitlement = $this->fixture->entitlements->add(Entitlement::grant(42, $courseId, Entitlement::SOURCE_MANUAL));

        $first = $this->fixture->enrolLearner->execute(42, $courseId, $entitlement->id());
        $second = $this->fixture->enrolLearner->execute(42, $courseId, $entitlement->id());

        self::assertSame($first->id(), $second->id());
        self::assertSame(1, $this->fixture->env->database->count(Query::from('enrolments')));
        self::assertCount(1, $this->fixture->eventsOfType(LearnerEnrolled::class));
        self::assertCount(0, $this->fixture->eventsOfType(EnrolmentResumed::class));
    }

    public function testSuspendedEnrolmentResumesUnderTheNewEntitlement(): void
    {
        $courseId = $this->fixture->createCourse();
        $old = $this->fixture->entitlements->add(Entitlement::grant(42, $courseId, Entitlement::SOURCE_MANUAL));
        $enrolment = $this->fixture->enrolLearner->execute(42, $courseId, $old->id());
        $this->fixture->enrolments->updateStatus($enrolment->id(), Enrolment::STATUS_SUSPENDED);

        $new = $this->fixture->entitlements->add(Entitlement::grant(42, $courseId, Entitlement::SOURCE_PURCHASE));
        $resumed = $this->fixture->enrolLearner->execute(42, $courseId, $new->id());

        self::assertSame($enrolment->id(), $resumed->id());
        self::assertSame(Enrolment::STATUS_ACTIVE, $resumed->status);
        self::assertSame($new->id(), $resumed->entitlementId);

        $row = $this->fixture->enrolmentRow(42, $courseId);
        self::assertNotNull($row);
        self::assertSame('active', $row['status']);
        self::assertSame($new->id(), (int) $row['entitlement_id']);

        $events = $this->fixture->eventsOfType(EnrolmentResumed::class);
        self::assertCount(1, $events);
        self::assertInstanceOf(EnrolmentResumed::class, $events[0]);
        self::assertSame($enrolment->id(), $events[0]->enrolmentId);
        self::assertCount(1, $this->fixture->eventsOfType(LearnerEnrolled::class));
    }

    public function testCompletedEnrolmentReturnsUnchangedWithoutEvents(): void
    {
        $courseId = $this->fixture->createCourse();
        $entitlement = $this->fixture->entitlements->add(Entitlement::grant(42, $courseId, Entitlement::SOURCE_MANUAL));
        $enrolment = $this->fixture->enrolLearner->execute(42, $courseId, $entitlement->id());
        $this->fixture->enrolments->updateStatus($enrolment->id(), Enrolment::STATUS_COMPLETED);
        $eventsBefore = \count($this->fixture->env->eventBridge->published);

        $result = $this->fixture->enrolLearner->execute(42, $courseId, $entitlement->id());

        self::assertSame($enrolment->id(), $result->id());
        self::assertSame(Enrolment::STATUS_COMPLETED, $result->status);
        self::assertCount($eventsBefore, $this->fixture->env->eventBridge->published);
    }
}
