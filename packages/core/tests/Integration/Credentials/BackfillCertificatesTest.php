<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\BackfillCertificates;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\BackfillCertificatesTask;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The reconciliation sweep the design names and nothing implemented.
 *
 * Completion is terminal and CourseCompleted cannot re-fire, so an issuance
 * that fails once leaves the enrolment complete for ever with no certificate
 * and nothing in the product ever looks for the gap. Two defects fixed in
 * this same pass, the unreissuable issuer earner id and the lapsed row that
 * never released the exactly-once key, are exactly the shape of failure this
 * is the safety net for.
 */
final class BackfillCertificatesTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner', 'asha@example.test');
        $this->templates->insert('Standard', 'html', 'Body', true, 'published');
    }

    public function testACompletedEnrolmentWithNoCertificateIsIssuedOne(): void
    {
        $this->seedCompletedEnrolment(10, 5, 7);

        $issued = $this->backfill()->execute();

        self::assertSame(1, $issued);
        self::assertNotNull($this->certificates->findActiveByEnrolmentId(10));
    }

    public function testBackfillDoesNotIssueAfterAccessIsWithdrawn(): void
    {
        $this->seedCompletedEnrolment(10, 5, 7);
        $entitlements = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEntitlementRepository(
            $this->ports->database, $this->ports->clock,
        );
        $backfill = new BackfillCertificates($this->ports->database, $this->issueWithSnapshot(),
            $this->ports->clock, $this->settings(), $entitlements);
        self::assertSame(0, $backfill->execute());
        self::assertNull($this->certificates->findActiveByEnrolmentId(10));
    }

    public function testTheSweepIsIdempotentAcrossRuns(): void
    {
        $this->seedCompletedEnrolment(10, 5, 7);

        $backfill = $this->backfill();

        self::assertSame(1, $backfill->execute());
        self::assertSame(0, $backfill->execute(), 'a second run re-issued a certificate that already existed');
    }

    public function testAnEnrolmentThatIsNotCompleteIsLeftAlone(): void
    {
        $this->seedEnrolment(11, 5, 7);

        self::assertSame(0, $this->backfill()->execute());
        self::assertNull($this->certificates->findActiveByEnrolmentId(11));
    }

    public function testTheRunStampsItsHeartbeatSoAnAdminScreenCanAnswer(): void
    {
        $this->seedCompletedEnrolment(10, 5, 7);
        $this->backfill()->execute();

        self::assertNotSame(
            '',
            $this->settings()->getString(BackfillCertificates::LAST_RUN_SETTING, ''),
        );
    }

    public function testTheTaskTypeIsTheOneTheDesignDeclares(): void
    {
        self::assertSame('vidyalms.certificate.backfill', BackfillCertificatesTask::TYPE);

        $task = new BackfillCertificatesTask($this->backfill());

        self::assertSame(BackfillCertificatesTask::TYPE, $task->key());
        self::assertSame(
            'certificate.backfill:2026-05-05:0',
            $task->periodicDedupeKey($task->periodBucket(new \DateTimeImmutable('2026-05-05 09:00:00')), 0),
        );
    }

    public function testRunningTheRegisteredHandlerIssuesTheMissingCertificate(): void
    {
        $this->seedCompletedEnrolment(10, 5, 7);

        $task = new BackfillCertificatesTask($this->backfill());
        $message = $task->run(new ScheduledTaskRun(
            1,
            ['binIndex' => 0, 'binCount' => 1],
            Principal::guest(),
        ));

        self::assertStringContainsString('issued 1', $message);
        self::assertNotNull($this->certificates->findActiveByEnrolmentId(10));
    }

    public function testARevokedCertificateIsNotAGapAndIsNeverReissuedBySweep(): void
    {
        $this->seedCompletedEnrolment(10, 5, 7);

        $backfill = $this->backfill();
        self::assertSame(1, $backfill->execute());

        $issued = $this->certificates->findActiveByEnrolmentId(10);
        self::assertNotNull($issued);
        $this->certificates->markRevoked($issued->id, $this->ports->clock->now(), 'issued_in_error');

        // Revocation clears active_enrolment_key, so a withdrawn certificate
        // looked exactly like an issuance that failed and the daily sweep
        // minted a replacement for it, verifying as valid, within a day.
        self::assertSame(0, $backfill->execute(), 'the sweep re-issued a deliberately revoked certificate');
        self::assertCount(1, $this->certificates->historyForEnrolment(10));
    }

    public function testDisabledCoursesDoNotStarveALaterEligibleEnrolment(): void
    {
        $this->seedCourse(8, 'Eligible course');
        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');
        $this->ports->database->insert('course_certificate_policies', [
            'course_id' => 7, 'revision' => 1, 'enabled' => 0, 'template_id' => null,
            'issue_rule' => 'course_completed', 'expiry' => '{"mode":"inherit_template"}',
            'co_branding' => 'site', 'updated_by' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        for ($id = 100; $id < 350; $id++) {
            $this->seedCompletedEnrolment($id, $id, 7);
        }
        $this->seedCompletedEnrolment(350, 5, 8);

        self::assertSame(1, $this->backfill()->execute());
        self::assertNull($this->certificates->findActiveByEnrolmentId(100));
        self::assertNotNull($this->certificates->findActiveByEnrolmentId(350));
        self::assertSame(0, $this->backfill()->execute());
    }

    private function backfill(): BackfillCertificates
    {
        return new BackfillCertificates(
            $this->ports->database,
            $this->issueWithSnapshot(),
            $this->ports->clock,
            $this->settings(),
        );
    }

    private function seedCompletedEnrolment(int $id, int $userId, int $courseId): void
    {
        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');

        $this->ports->database->insert('enrolments', [
            'id' => $id,
            'user_id' => $userId,
            'course_id' => $courseId,
            'entitlement_id' => 1,
            'status' => 'completed',
            'enrolled_at' => $now,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
