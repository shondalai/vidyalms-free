<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class SelfEnrolTest extends TestCase
{
    private AccessFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = AccessFixture::create();
    }

    public function testFreePublishedCourseEnrolsTheCaller(): void
    {
        $courseId = $this->fixture->createCourse();

        $enrolment = $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);

        self::assertSame(Enrolment::STATUS_ACTIVE, $enrolment->status);
        self::assertSame(42, $enrolment->userId);

        $entitlementRow = $this->fixture->env->database->selectOne(
            Query::from('entitlements')->where('user_id', '=', 42)->where('course_id', '=', $courseId),
        );
        self::assertNotNull($entitlementRow);
        self::assertSame('open', $entitlementRow['source']);
        self::assertSame('active', $entitlementRow['status']);

        self::assertSame(
            ['vidyalms.entitlement.granted', 'vidyalms.enrolment.created'],
            $this->fixture->env->eventBridge->eventNames(),
        );
    }

    public function testPaidCourseRaisesConflictDirectingToPurchase(): void
    {
        $courseId = $this->fixture->createCourse(['price_mode' => 'paid']);

        try {
            $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);
            self::fail('Expected a ConflictException for the paid course.');
        } catch (ConflictException $exception) {
            self::assertStringContainsString('Purchase', $exception->getMessage());
        }

        self::assertSame([], $this->fixture->env->eventBridge->published);
        self::assertSame(0, $this->fixture->env->database->count(Query::from('entitlements')));
    }

    public function testGuestIsDenied(): void
    {
        $courseId = $this->fixture->createCourse();

        try {
            $this->fixture->selfEnrol->execute(Principal::guest(), $courseId);
            self::fail('Expected a NotAuthorizedException for the guest.');
        } catch (NotAuthorizedException $exception) {
            self::assertSame([], $this->fixture->env->eventBridge->published);
        }
    }

    public function testDoubleSubmitReturnsTheSameEnrolmentWithoutNewEvents(): void
    {
        $courseId = $this->fixture->createCourse();

        $first = $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);
        $second = $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);

        self::assertSame($first->id(), $second->id());
        self::assertSame(1, $this->fixture->env->database->count(Query::from('enrolments')));
        self::assertSame(1, $this->fixture->env->database->count(Query::from('entitlements')));
        self::assertCount(2, $this->fixture->env->eventBridge->published);
    }

    public function testUnpublishedCourseRaisesConflict(): void
    {
        $courseId = $this->fixture->createCourse(['state' => 'draft']);

        $this->expectException(ConflictException::class);
        $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);
    }

    public function testUnknownCourseIsRejected(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->fixture->selfEnrol->execute(Principal::user(42), 999);
    }

    public function testCompletedEnrolmentIsReturnedUnchanged(): void
    {
        $courseId = $this->fixture->createCourse();
        $enrolment = $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);

        $this->fixture->enrolments->updateStatus($enrolment->id(), Enrolment::STATUS_COMPLETED);
        $eventsBefore = \count($this->fixture->env->eventBridge->published);

        $again = $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);

        self::assertSame($enrolment->id(), $again->id());
        self::assertSame(Enrolment::STATUS_COMPLETED, $again->status);
        self::assertCount($eventsBefore, $this->fixture->env->eventBridge->published);
        self::assertSame(1, $this->fixture->env->database->count(Query::from('entitlements')));
    }

    /**
     * A site policy switched off is expressed as a replacement provider that
     * refuses, never as a guard in whichever adapter wrote the caller. This is
     * the contract both adapters' SiteSelfEnrolmentRule rests on: the refusal
     * has to reach SelfEnrol itself, so the page, the form post, POST
     * /enrolments and any other caller get the same answer.
     */
    public function testAReplacementRuleThatRefusesStopsSelfEnrolment(): void
    {
        $courseId = $this->fixture->createCourse();
        $switch = SwitchableEnrolmentRule::install($this->fixture->enrolmentRules);
        $switch->enabled = false;

        try {
            $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);
            self::fail('Expected a ConflictException from the refusing enrolment rule.');
        } catch (ConflictException $exception) {
            self::assertStringContainsString('Self-enrolment is not available', $exception->getMessage());
        }

        self::assertSame([], $this->fixture->env->eventBridge->published);
        self::assertSame(0, $this->fixture->env->database->count(Query::from('entitlements')));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('enrolments')));
    }

    /**
     * The other half of the same contract: with the switch on, the replacement
     * delegates to the rule it displaced rather than deciding anything itself,
     * so the free build's "published and free" answer still governs.
     */
    public function testTheSameReplacementDelegatesWhenTheSwitchIsOn(): void
    {
        $courseId = $this->fixture->createCourse();
        $paidId = $this->fixture->createCourse(['price_mode' => 'paid', 'slug' => 'paid-course']);
        $switch = SwitchableEnrolmentRule::install($this->fixture->enrolmentRules);
        $switch->enabled = true;

        $enrolment = $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);
        self::assertSame(Enrolment::STATUS_ACTIVE, $enrolment->status);

        // Delegation, not a blanket yes: the displaced rule still refuses a paid course.
        $this->expectException(ConflictException::class);
        $this->fixture->selfEnrol->execute(Principal::user(42), $paidId);
    }
}
