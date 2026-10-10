<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Progress;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Progress\RecomputeCourseProgressForCourse;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Tests\Support\QueueScheduler;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The archive's course-wide recompute, drained the way a host drains it:
 * claim a row, dispatch it through the registered handler for its type, stamp
 * the row it claimed. Both halves of that are the point. A type with no
 * handler is a failed row on both hosts, and a continuation that reuses the
 * key of the row being drained is a batch nobody runs.
 */
final class QueuedCourseRecomputeTest extends TestCase
{
    private const COURSE_ID = 7001;

    private TestPorts $env;

    private QueueScheduler $queue;

    private CoreKernel $kernel;

    protected function setUp(): void
    {
        $this->env = TestPorts::create(true);
        $this->queue = new QueueScheduler();

        $ports = new Ports(
            database: $this->env->database,
            users: $this->env->users,
            authorization: $this->env->authorization,
            storage: $this->env->storage,
            mailer: $this->env->mailer,
            scheduler: $this->queue,
            eventBridge: $this->env->eventBridge,
            commerce: $this->env->commerce,
            translator: $this->env->translator,
            cache: $this->env->cache,
            clock: $this->env->clock,
            random: $this->env->random,
            logger: new \Psr\Log\NullLogger(),
        );

        $this->kernel = new CoreKernel($ports, ['verification_base_url' => 'https://example.test/verify']);
    }

    public function testTheCourseRecomputeTypeHasARegisteredHandler(): void
    {
        self::assertTrue(
            $this->kernel->registries->scheduledTasks->has(RecomputeCourseProgressForCourse::TASK_TYPE),
            'an archive queues this type, so a host with no handler for it fails every archive silently',
        );
    }

    public function testEveryTypeTheFreeBuildQueuesIsHandled(): void
    {
        $keys = $this->kernel->registries->scheduledTasks->keys();

        sort($keys);

        self::assertSame([
            // The report artefact sweep. It is the only thing that deletes a
            // file the reporting pipeline wrote, and it is free because the
            // artefacts are personal data on every site: a retention date the
            // schema declares and nothing enforces is a retention policy
            // nobody has. Its key is "reports.sweep" and not
            // "vidyalms.report.sweep" because the design names the scheduled
            // task types in that form and the stored key outlives the wave.
            // The scheduled report runner, free for the reason the schedule
            // table is free: the per-recipient render and the two delivery
            // refusals are the security half of scheduled reporting, and a
            // security property that arrives with a paid package is a property
            // the sites most likely to need it do not have. It mints the
            // occurrence, generates, stores and hands the run to a delivery
            // provider, and it builds no mailer of its own.
            'privacy.files.cleanup',
            'privacy.logs.cleanup',
            'reports.schedule',
            'reports.sweep',
            // The five vidyalms.ai.* types, all free and all reachable on a site
            // with no model configured at all.
            //
            // vidyalms.ai.chunk is keyed per course rather than run
            // periodically, because re-indexing answers an edit and not a clock:
            // a site with four thousand courses would otherwise re-read all of
            // them nightly to find the three that changed.
            //
            // vidyalms.ai.retain purges past retain_until and leaves
            // ai_artefact_provenance untouched, which is what makes the
            // editorial-responsibility record outlive the prompt log that
            // produced it. It also releases the reservation of any run left
            // non-terminal past its deadline, because a reservation that never
            // settles holds ceiling headroom for ever.
            //
            // vidyalms.ai.stale_drafts is the eighth type the design adds
            // because setting retain_until only at a terminal state left the
            // drafts nobody ever opened matching no purge predicate at all. It
            // moves an idle draft to discarded with a countable reason code, so
            // an abandoned draft is decided about rather than merely aged out.
            //
            // vidyalms.ai.reconcile resolves bridges from the registry and calls
            // only those declaring a counterparty ledger, so it names no vendor
            // and does nothing on a free site. It remains registered so a
            // queued AI job can still resolve after removing a paid package.
            'vidyalms.ai.chunk',
            'vidyalms.ai.reconcile',
            'vidyalms.ai.retain',
            'vidyalms.ai.run',
            'vidyalms.ai.stale_drafts',
            // The free automation engine's three types. The sweep and the
            // fan-out are started by the clock because nothing else can
            // start them: an intent row is written by the use case that
            // changed the state and a sweep has no moment of its own, so a
            // build where either did not declare RunsPeriodically leaves
            // every rule registered and never fired. The step type's dedupe
            // key is the step's own idempotency digest, so the job, the
            // queue row and the ledger claim all name one effect.
            'vidyalms.automation.fanout',
            'vidyalms.automation.step',
            'vidyalms.automation.sweep',
            // The unlock notice and the three notification jobs are the
            // learning-flow half of this list. Access never depends on any of
            // them: the engine evaluates on read, and both hosts' schedulers
            // are documented to fire late or not at all.
            'vidyalms.availability.unlock',
            // Bulk reissue is two types, not one: the fan-out resumes at the
            // template and each per-certificate task resumes at the
            // certificate, so a restart does not re-select what it has
            // already written and one failure is one row.
            //
            // The backfill is the reconciliation sweep for issuance:
            // completion is terminal and CourseCompleted cannot re-fire, so a
            // failed issuance leaves an enrolment complete for ever with no
            // certificate and nothing else would look for the gap.
            'vidyalms.certificate.backfill',
            'vidyalms.certificate.reissue',
            'vidyalms.certificate.reissue_one',
            'vidyalms.certificate.render',
            'vidyalms.course.recompute_progress',
            'vidyalms.entitlements.expire',
            'vidyalms.import.run',
            'vidyalms.notification.fanout',
            'vidyalms.notification.send',
            'vidyalms.notification.sweep',
            // The only writer that removes a row from the admin access log.
            // It is registered in the free build because the log it prunes is
            // free, and a retention floor nothing enforces is a table that
            // grows for ever on every site that never buys anything.
            'vidyalms.organisation.access_log_retention',
            // The four free organisation sweeps. Every one of them acts on a
            // column that was already being written and read by nothing:
            // invitations.expires_at, seat_pools.ends_at,
            // seat_assignments.reserved_until, and the two derivations of a
            // pool's balance that have to agree for the ledger to be a
            // balance rather than a counter. They are free because the seat
            // pool and the invitation are free, and a site whose scheduler
            // never runs one of them ends up with seats held for people who
            // never accepted.
            // The domain re-check and the provisioning sweep. Both are
            // registered in the free build and both answer honestly on a stock
            // one: no verifier is installed, so nothing is demoted, and no
            // registered source sweeps incrementally, so nothing is swept. They
            // are registered anyway because a task key is stored in host job
            // rows and both drains resolve a handler by it: a key that appeared
            // only when a paid package was installed would fail to resolve on
            // every site that later removed one, and a job of that type would
            // sit pending for ever.
            // The daily compliance walk. It ships free with no rule registered
            // into the ComplianceRule seam, which is the point of running it:
            // every open assignment is re-asked once a day, so the day a paid
            // rule is registered the answers change with no core edit, and
            // until then every answer is provably not compliant.
            'vidyalms.organisation.compliance_window',
            'vidyalms.organisation.domain_recheck',
            'vidyalms.organisation.invitation_expiry',
            'vidyalms.organisation.membership_term_sweep',
            'vidyalms.organisation.pool_term_sweep',
            'vidyalms.organisation.provisioning_sync',
            'vidyalms.organisation.reservation_expiry',
            'vidyalms.organisation.seat_reconciliation',
            'vidyalms.organisation.terminate_access',
            'vidyalms.progress.recompute',
            'vidyalms.report.generate',
        ], $keys);
    }

    public function testDrainingRecomputesEveryEnrolmentAcrossBatches(): void
    {
        $total = RecomputeCourseProgressForCourse::BATCH_SIZE + 5;
        $this->seedCourse($total);

        $this->kernel->recomputeCourseProgressForCourse->queue(self::COURSE_ID);

        $runs = $this->drain();

        self::assertGreaterThan(1, $runs, 'a course larger than one batch takes more than one run');
        self::assertSame($total, $this->recomputedEnrolments(), 'every enrolment of the course was recomputed');
    }

    public function testAnUnknownTypeIsReportedRatherThanRun(): void
    {
        self::assertNull(
            $this->kernel->runScheduledTask('vidyalms.not.a.type', 1, [], Principal::guest()),
            'a runner tells the type apart from a failure, so it can say which it was',
        );
    }

    /**
     * Claim, dispatch, stamp: one bounded pass per iteration, exactly as both
     * hosts' runners do it.
     */
    private function drain(): int
    {
        $runs = 0;

        while ($runs < 20) {
            $claimed = $this->queue->claim();

            if ($claimed === null) {
                return $runs;
            }

            $runs++;

            $outcome = $this->kernel->runScheduledTask(
                $claimed['task']->type,
                $claimed['id'],
                $claimed['task']->payload,
                Principal::guest(),
            );

            self::assertNotNull($outcome, 'no free build task type may reach a runner unhandled');

            $this->queue->finish($claimed['id']);
        }

        self::fail('the drain did not settle');
    }

    private function recomputedEnrolments(): int
    {
        $rows = $this->env->database->selectAll(
            Query::from('course_progress', 'p')
                ->select(['p.id'])
                ->join('enrolments', 'e', 'e.id', 'p.enrolment_id')
                ->where('e.course_id', '=', self::COURSE_ID),
        );

        return \count($rows);
    }

    private function seedCourse(int $enrolments): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        $this->env->database->insert('courses', [
            'id' => self::COURSE_ID,
            'title' => 'Batched',
            'slug' => 'batched',
            'state' => 'published',
            'visibility' => 'public',
            'price_mode' => 'free',
            'completion_rule' => 'all_lessons',
            'created_by' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        for ($index = 0; $index < $enrolments; $index++) {
            $userId = 6000 + $index;

            $entitlementId = $this->env->database->insert('entitlements', [
                'user_id' => $userId,
                'course_id' => self::COURSE_ID,
                'source' => 'manual',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->env->database->insert('enrolments', [
                'user_id' => $userId,
                'course_id' => self::COURSE_ID,
                'entitlement_id' => $entitlementId,
                'status' => 'active',
                'enrolled_at' => $now,
                'progress_percent' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
