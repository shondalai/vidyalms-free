<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeItem;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportSchedule;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportScopeDigest;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Grades\GradeBookStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationAudienceSpan;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\MeanGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\NaturalGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\Report\AtRiskLearnersReport;
use Shondalai\VidyaLMS\Core\Provider\Report\EnrolmentRosterReport;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ScheduleReportsTask;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * R9b's free reporting half, driven against a real site and a real database.
 *
 * One body of assertions and two callers, exactly as {@see OrganisationSeatContract}
 * and {@see AiFreeBuildContract} are run: each free adapter's build/smoke.php
 * runs it against its own ports. The core suite already proves the arithmetic,
 * the vocabularies and the refusals against an in-memory SQLite harness, so
 * every section here is chosen because SQLite would have said yes:
 *
 *   - **A graded submission fills the gradebook.** Four new tables, two of them
 *     carrying DECIMAL(12,5) columns a host driver hands back as strings and one
 *     carrying a JSON payload, written by the shipped listener rather than by
 *     this section, and read back through the host's own query compiler. A
 *     driver that rounded a decimal or lost a snapshot maximum would be a grade
 *     nobody can interpret afterwards.
 *   - **A regrade lands in the change log.** The append-only table with no
 *     `updated_at`, which is the timestamps opt-out the schema grammar has and
 *     the one shape a host migration is most likely to get wrong.
 *   - **The aggregation answers over stored cells.** The registry is the only
 *     arithmetic, and an unregistered key answers nothing rather than guessing.
 *   - **The learner timeline pages.** A keyset walk on the exact
 *     `(occurred_at, id)` comparison over the highest-volume personal data table
 *     in the product, where a host collation that compares differently is the
 *     difference between a page that skips a row and a page that repeats one.
 *   - **A schedule renders per recipient into the notification queue, and both
 *     crossings are refused.** The security half of scheduled reporting, proved
 *     against this site's own authorisation port: the recipient who may read the
 *     report is served and stamped on the run, the recipient who may not is
 *     skipped without failing the schedule, and both R5 refusals fire before a
 *     run row opens.
 *
 * **Everything it writes goes inside a transaction it rolls back**, exactly as
 * {@see ProReportingContract} does, and a check afterwards proves the rollback
 * rather than assuming it. The one thing a transaction cannot undo is a file, so
 * every artefact the export pipeline wrote is deleted by path in a finally.
 *
 * **It creates no table and drops none, and it never names a Community Quiz
 * table.**
 */
final class GradebookReportingContract
{
    /** Everything this contract creates carries it, so the cleanup can be exact. */
    public const MARKER = 'vidyalms-gradebook-contract';

    /** The tables the rollback check reads, and the column each is marked in. */
    private const MARKED_BY = [
        'report_schedules' => 'title',
        'assignments' => 'instructions',
    ];

    /** @var list<string> */
    private array $facts = [];

    /** @var list<string> */
    private array $failures = [];

    /** Artefact paths the pipeline wrote, removed by path because a file has no transaction. */
    /** @var list<string> */
    private array $artefacts = [];

    private int $lessonId = 0;

    private int $assignmentId = 0;

    private int $submissionId = 0;

    private int $cellId = 0;

    private int $gradeItemId = 0;

    private int $runsWritten = 0;

    private function __construct(
        private readonly CoreKernel $kernel,
        private readonly Ports $ports,
        private readonly Principal $actor,
        private readonly int $courseId,
        private readonly int $learnerId,
    ) {
    }

    /**
     * @return array{failures: list<string>, facts: list<string>, runs: int}
     */
    public static function run(
        CoreKernel $kernel,
        Ports $ports,
        Principal $actor,
        int $courseId,
        int $learnerId,
    ): array {
        $contract = new self($kernel, $ports, $actor, $courseId, $learnerId);

        try {
            $ports->database->transactional(static function () use ($contract): void {
                $contract->seed();
                $contract->checkAGradedSubmissionWritesTheItemTheCellAndTheChange();
                $contract->checkARegradeIsRecordedWithItsReasonAndItsActor();
                $contract->checkTheAggregationAnswersOverStoredCells();
                $contract->checkTheLearnerTimelinePages();
                $contract->checkAScheduleRendersPerRecipientIntoTheQueue();
                $contract->checkBothCrossingsAreRefusedBeforeARunRowOpens();

                throw new RollbackSignal();
            });
        } catch (RollbackSignal) {
            // The intended exit: the work is done and the transaction is gone.
        } catch (\Throwable $error) {
            $contract->failures[] = 'threw ' . $error::class . ': ' . $error->getMessage();
        } finally {
            $contract->removeEveryArtefact();
        }

        $contract->assertNothingSurvived();

        return [
            'failures' => $contract->failures,
            'facts' => $contract->facts,
            'runs' => $contract->runsWritten,
        ];
    }

    // -----------------------------------------------------------------------
    // The fixture
    // -----------------------------------------------------------------------

    /**
     * An enrolled learner and a graded assignment on the course this section
     * was given, because the gradebook's one shipped writer for a human mark
     * is the listener that hears a submission graded.
     */
    private function seed(): void
    {
        $this->kernel->grantEntitlement->execute(
            $this->actor,
            $this->learnerId,
            $this->courseId,
            Entitlement::SOURCE_MANUAL,
            self::MARKER,
        );

        $module = $this->kernel->addModule->execute($this->actor, $this->courseId, 'Graded work');
        $lesson = $this->kernel->addLesson->execute(
            $this->actor,
            (int) $module->id,
            'The graded assignment',
            'assignment',
            ['body' => '<p>Submitted and marked by the gradebook contract.</p>'],
        );
        $this->lessonId = (int) $lesson->id;

        $assignment = $this->kernel->configureAssignment->execute(
            $this->actor,
            $this->lessonId,
            self::MARKER,
            20.0,
        );
        $this->assignmentId = (int) $assignment->id;

        $submission = $this->kernel->submitAssignment->execute(
            Principal::user($this->learnerId),
            $this->lessonId,
            'The answer the contract submits.',
            [],
        );
        $this->submissionId = (int) $submission->id;
    }

    // -----------------------------------------------------------------------
    // The contract
    // -----------------------------------------------------------------------

    /**
     * Marking the submission writes the item, the cell and the change, and the
     * cell snapshots the maximum the marker actually used.
     */
    private function checkAGradedSubmissionWritesTheItemTheCellAndTheChange(): void
    {
        $before = $this->ports->database->count(
            Query::from(GradeBookStore::CELLS)->where('course_id', '=', $this->courseId),
        );

        $this->kernel->gradeSubmission->execute($this->actor, $this->submissionId, 15.0, 'Well argued.');

        $item = $this->ports->database->selectOne(
            Query::from(GradeBookStore::ITEMS)
                ->where('course_id', '=', $this->courseId)
                ->where('source_kind', '=', GradeItem::SOURCE_ASSIGNMENT)
                ->where('source_id', '=', $this->assignmentId),
        );

        $this->expect(
            $item !== null,
            'marking a submission created the grade item for the assignment it marked',
            'no grade_items row for assignment ' . $this->assignmentId,
        );

        if ($item === null) {
            return;
        }

        $this->gradeItemId = (int) $item['id'];

        $cell = $this->ports->database->selectOne(
            Query::from(GradeBookStore::CELLS)
                ->where('grade_item_id', '=', $this->gradeItemId)
                ->where('user_id', '=', $this->learnerId),
        );

        $this->expect(
            $cell !== null,
            'marking a submission created the learner\'s grade cell',
            'no grade_cells row for item ' . $this->gradeItemId,
        );

        if ($cell === null) {
            return;
        }

        $this->cellId = (int) $cell['id'];
        $after = $this->ports->database->count(
            Query::from(GradeBookStore::CELLS)->where('course_id', '=', $this->courseId),
        );

        // The snapshot is the whole reason raw_grade_max is a column. The
        // marker used a maximum of twenty, and the row has to keep saying so
        // when somebody moves the assignment to a hundred next term.
        $this->expect(
            $after === $before + 1
            && abs((float) $cell['raw_grade'] - 15.0) < 0.00001
            && abs((float) $cell['raw_grade_max'] - 20.0) < 0.00001,
            'the cell carries the mark and the maximum the marker used, through this host\'s decimal columns',
            'raw_grade=' . self::describe($cell['raw_grade'] ?? null)
                . ' raw_grade_max=' . self::describe($cell['raw_grade_max'] ?? null),
        );

        // The two interchange vocabularies, stored as words rather than as
        // this product's own invented ones, so a gradebook export means the
        // same thing to somebody who has never read this codebase.
        $this->expect(
            (string) $cell['grading_progress'] === 'FullyGraded'
            && (string) $cell['score_status'] === 'fully graded',
            'the cell speaks the two interchange vocabularies rather than a third of its own',
            'grading_progress=' . self::describe($cell['grading_progress'] ?? null)
                . ' score_status=' . self::describe($cell['score_status'] ?? null),
        );

        $changes = $this->ports->database->count(
            Query::from(GradeBookStore::CHANGES)->where('grade_cell_id', '=', $this->cellId),
        );

        $this->expect(
            $changes === 1,
            'the first grade is itself an entry in the change log',
            $changes . ' grade_changes row(s)',
        );
    }

    /**
     * A person changing a grade says why, and the log carries both the reason
     * and who they were.
     */
    private function checkARegradeIsRecordedWithItsReasonAndItsActor(): void
    {
        if ($this->cellId === 0) {
            $this->failures[] = 'no grade cell exists, so the regrade could not be driven';

            return;
        }

        $refused = false;

        try {
            $this->kernel->regradeGradeCell->execute($this->actor, $this->cellId, ['raw_grade' => 18.0]);
        } catch (ValidationException $error) {
            $refused = \array_key_exists('reason', $error->errors);
        }

        $this->expect($refused, 'a regrade with no reason is refused', 'the blank reason was accepted');

        $answer = $this->kernel->regradeGradeCell->execute(
            $this->actor,
            $this->cellId,
            ['raw_grade' => 18.0, 'reason' => 'Second marker agreed, ' . self::MARKER],
        );

        $log = $this->ports->database->selectAll(
            Query::from(GradeBookStore::CHANGES)
                ->where('grade_cell_id', '=', $this->cellId)
                ->orderBy('id', Query::SORT_ASC),
        );

        $latest = $log === [] ? null : $log[array_key_last($log)];

        $this->expect(
            \count($log) === 2
            && $latest !== null
            && abs((float) $latest['grade_before'] - 15.0) < 0.00001
            && abs((float) $latest['grade_after'] - 18.0) < 0.00001
            && (int) $latest['actor_id'] === $this->actor->userId
            && str_contains((string) $latest['reason'], self::MARKER),
            'the regrade is in the log with what it moved, who moved it and why',
            \count($log) . ' row(s), latest ' . self::describe($latest),
        );

        // The append-only table has no updated_at, which is the timestamps
        // opt-out the schema grammar has. A host migration that added one
        // anyway would be a log row somebody can touch without leaving a trace.
        $this->expect(
            $latest !== null && !\array_key_exists('updated_at', $latest)
            && \array_key_exists('occurred_at', $latest),
            'a change log row is stamped when it happened and never when it was last written',
            $latest === null ? 'no row' : implode(', ', array_keys($latest)),
        );

        $this->expect(
            \is_array($answer['totals'] ?? null) && \is_array($answer['change'] ?? null),
            'the regrade answers the cell, the change and the recomputed totals in one reply',
            self::describe(array_keys($answer)),
        );
    }

    /** The registry is the only arithmetic, and it answers over the stored cells. */
    private function checkTheAggregationAnswersOverStoredCells(): void
    {
        $registry = $this->kernel->registries->gradeAggregations;

        $this->expect(
            $registry->has(NaturalGradeAggregation::KEY) && $registry->has(MeanGradeAggregation::KEY),
            'the free build offers exactly the two aggregations it ships',
            implode(', ', $registry->keys()),
        );

        $totals = $this->kernel->readGradebook->totalsFor($this->courseId, $this->learnerId);

        $this->expect(
            abs((float) ($totals['current'] ?? 0.0) - 18.0) < 0.00001
            && abs((float) ($totals['grade_max'] ?? 0.0) - 20.0) < 0.00001
            && (string) ($totals['aggregation_status'] ?? '') === 'used'
            && (string) ($totals['aggregation_key'] ?? '') === NaturalGradeAggregation::KEY,
            'the natural aggregation folds the stored cell into eighteen of twenty',
            self::describe($totals),
        );

        // The published miss direction, applied rather than guessed. A total
        // computed under a rule nobody asked for is a grade dispute nobody can
        // trace, so an unresolved key answers nothing and says which key it was.
        // The key has to be one no provider claims, and that has to be proved
        // rather than assumed: this used to name 'weighted-mean', which the free
        // build has shipped since WeightedMeanGradeAggregation landed, so the
        // assertion was reading a resolved provider and calling it a miss.
        $absentKey = 'vidyalms-contract-no-such-aggregation';

        $this->expect(
            !$registry->has($absentKey),
            'the miss direction is exercised with a key no provider on this site claims',
            $absentKey . ' against ' . implode(', ', $registry->keys()),
        );

        $this->ports->database->update(
            GradeBookStore::ITEMS,
            ['aggregation_key' => $absentKey],
            ['id' => $this->gradeItemId],
        );

        $items = $this->kernel->readGradebook->items($this->actor, $this->courseId);
        $missed = null;

        foreach ($items['items'] as $candidate) {
            if ((int) $candidate['id'] === $this->gradeItemId) {
                $missed = $candidate;
            }
        }

        $this->expect(
            $missed !== null
            && $missed['aggregation_available'] === false
            && $missed['aggregation_label'] === null
            && (string) $missed['aggregation_key'] === $absentKey,
            'an item naming a provider this site does not have says so rather than guessing',
            self::describe($missed),
        );

        $this->ports->database->update(
            GradeBookStore::ITEMS,
            ['aggregation_key' => NaturalGradeAggregation::KEY],
            ['id' => $this->gradeItemId],
        );
    }

    /**
     * The timeline is a keyset walk over the highest-volume personal data table
     * in the product, and the walk has to be exact on this host's collation.
     */
    private function checkTheLearnerTimelinePages(): void
    {
        $whole = $this->kernel->getLearnerTimeline->execute($this->actor, $this->learnerId, null, null, null, 50);

        $this->expect(
            ($whole['totalItems'] ?? 0) > 1,
            'the learner has a timeline to page through',
            self::describe($whole['totalItems'] ?? null),
        );

        if (($whole['totalItems'] ?? 0) < 2) {
            return;
        }

        $expected = array_map(
            static fn (array $item): string => (string) ($item['id'] ?? ''),
            $whole['orderedItems'],
        );

        $walked = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $this->kernel->getLearnerTimeline->execute(
                $this->actor,
                $this->learnerId,
                null,
                null,
                $cursor,
                1,
            );

            foreach ($page['orderedItems'] as $item) {
                $walked[] = (string) ($item['id'] ?? '');
            }

            $cursor = $page['next'];
            $pages++;
        } while ($cursor !== null && $pages < \count($expected) + 2);

        $this->expect(
            $walked === $expected,
            'a one-at-a-time keyset walk repeats no entry and skips none',
            \count($walked) . ' walked over ' . $pages . ' page(s) against ' . \count($expected) . ' in one read',
        );

        // Activity Streams 2.0 property names, because a timeline that invented
        // its own would need a mapper on every side that reads it.
        $first = $whole['orderedItems'][0] ?? [];

        $this->expect(
            \array_key_exists('id', $first)
            && \array_key_exists('type', $first)
            && \array_key_exists('published', $first),
            'a timeline entry carries the interchange property names',
            self::describe(array_keys((array) $first)),
        );
    }

    /**
     * One schedule, two recipients, and the difference between them is what
     * this section is about.
     */
    private function checkAScheduleRendersPerRecipientIntoTheQueue(): void
    {
        $this->kernel->registries->audiences->register(
            new SmokeTwoNamedRecipients([$this->actor->userId, $this->learnerId]),
        );

        $schedule = $this->kernel->reportSchedules->save(ReportSchedule::create(
            self::MARKER . ' roster',
            EnrolmentRosterReport::KEY,
            SmokeTwoNamedRecipients::KEY,
            $this->ports->clock->now()->modify('-1 day'),
            $this->actor->userId,
            'csv',
            'notification-link',
            ['course_id' => $this->courseId],
            [],
            ReportSchedule::RECURRENCE_DAILY,
            1,
            0,
            'UTC',
            ReportSchedule::RENDER_AS_RECIPIENT,
            ReportSchedule::EMPTY_SEND,
            null,
            null,
            null,
            ReportSchedule::STATE_ENABLED,
        ));

        $scheduleId = (int) $schedule->id;

        $this->expect(
            (string) $schedule->renderAs === ReportSchedule::RENDER_AS_RECIPIENT,
            'a saved schedule renders per recipient unless its author says otherwise',
            (string) $schedule->renderAs,
        );

        $task = $this->kernel->registries->scheduledTasks->has(ScheduleReportsTask::KEY)
            ? $this->kernel->registries->scheduledTasks->get(ScheduleReportsTask::KEY)
            : null;

        $this->expect(
            $task !== null,
            'this site registers the reports.schedule task type without this host naming it',
            ScheduleReportsTask::KEY . ' is not in the registry',
        );

        if ($task === null) {
            return;
        }

        $summary = $task->run(new ScheduledTaskRun(0, [], $this->actor));

        $runs = $this->ports->database->selectAll(
            Query::from('report_runs')->where('schedule_id', '=', $scheduleId)->orderBy('id', Query::SORT_ASC),
        );
        $this->runsWritten = \count($runs);

        // One recipient holds the report's capability on this site and one does
        // not. The one who does is served and stamped; the one who does not is
        // skipped, and the schedule is not failed by them. That is the whole
        // per recipient rule, proved against this site's own authorisation port.
        $this->expect(
            \count($runs) === 1
            && (int) $runs[0]['rendered_as'] === $this->actor->userId
            && (string) $runs[0]['report_key'] === EnrolmentRosterReport::KEY,
            'the recipient who may read the report gets a run stamped with their own id, and the other does not',
            \count($runs) . ' run(s): ' . self::describe(array_column($runs, 'rendered_as')),
        );

        foreach ($runs as $run) {
            if (\is_string($run['storage_path'] ?? null) && $run['storage_path'] !== '') {
                $this->artefacts[] = (string) $run['storage_path'];
            }
        }

        // A real site has schedules of its own, and one turn serves every one
        // that is due, so `served` is a count of the site rather than of this
        // section. What this section owns is the failure count, which must be
        // zero, and that its own schedule was among the ones served, which the
        // run rows above already say exactly. Asserting `served=1` made the
        // check pass only on a site with no other enabled schedule, which is
        // the one site this contract is not designed for.
        $served = preg_match('/served=(\d+)/', $summary, $matches) === 1 ? (int) $matches[1] : 0;

        $this->expect(
            $served >= 1 && \count($runs) === 1 && str_contains($summary, 'failed=0'),
            'the turn served this schedule among the site own due ones and failed nothing',
            $summary . ', ' . \count($runs) . ' run(s) for schedule ' . $scheduleId,
        );

        // The message rides the notification engine and there is no second
        // mailer. A queue row is the proof of that, because the alternative
        // leaves no row anywhere.
        //
        // Anchored on this section's own run rather than on a site-wide count,
        // for the reason the served count above is not asserted as one: a turn
        // serves every schedule that is due, and any other enabled schedule on
        // the site queues a row of its own inside the same turn. A delta of
        // exactly one passed only on a site whose only due schedule was this
        // one, which is the site this contract is not written for.
        $ownRunId = (int) ($runs[0]['id'] ?? 0);

        $queuedForOwnRun = $ownRunId > 0
            ? $this->ports->database->count(
                Query::from('notification_queue')
                    ->where('subject_type', '=', 'report_run')
                    ->where('subject_id', '=', $ownRunId),
            )
            : 0;

        $this->expect(
            $queuedForOwnRun === 1,
            'the artefact reached its recipient through the notification queue and not through a second mailer',
            $queuedForOwnRun . ' queue row(s) for run ' . $ownRunId,
        );

        $advanced = $this->ports->database->selectOne(
            Query::from('report_schedules')->where('id', '=', $scheduleId),
        );

        $this->expect(
            $advanced !== null && (int) $advanced['run_count'] === 1 && $advanced['next_run_at'] !== null,
            'the schedule recorded its run and moved to its next boundary',
            self::describe($advanced === null ? null : [
                'run_count' => $advanced['run_count'] ?? null,
                'next_run_at' => $advanced['next_run_at'] ?? null,
                'last_error' => $advanced['last_error'] ?? null,
            ]),
        );
    }

    /**
     * Both R5 refusals, and both of them before a run row opens.
     *
     * A refusal that fired after the artefact existed would leave a completed
     * run holding personal data nobody was ever allowed to receive, which is
     * worse than the delivery it prevented.
     */
    private function checkBothCrossingsAreRefusedBeforeARunRowOpens(): void
    {
        $report = $this->kernel->registries->reports->has(AtRiskLearnersReport::KEY)
            ? $this->kernel->registries->reports->get(AtRiskLearnersReport::KEY)
            : null;

        if (!$report instanceof TabularReportInterface) {
            $this->failures[] = 'the at-risk report is not registered, so neither crossing could be driven';

            return;
        }

        $attaching = new SmokeAttachingDelivery();
        $this->kernel->registries->reportDeliveries->register($attaching);

        $before = $this->ports->database->count(Query::from('report_runs'));
        $recipients = [$this->actor->userId, $this->learnerId];

        // The crossing is established rather than assumed. Both refusals fire
        // on a recipient set that spans more than one organisation, and the
        // span is counted from org_memberships: a recipient nobody can place
        // is their own span of one. On an empty fixture both recipients are
        // unplaced, so the span was two by accident and the section read as
        // green; on a real site the harness has already put both of them
        // inside the organisation it created, so the span was one and both
        // crossings were allowed with nothing failing. Seating exactly one of
        // the two in an organisation of this section's own makes the span at
        // least two whatever the host already holds, and the assertion below
        // says so before either refusal is driven.
        $this->seatOneRecipientApart();
        $span = (new OrganisationAudienceSpan($this->ports->database))->spanOf($recipients);

        $this->expect(
            $span >= 2,
            'the recipient set really does span more than one organisation before the refusals are driven',
            'span ' . $span . ' over ' . self::describe($recipients),
        );

        $authorRefused = '';

        try {
            $this->kernel->reportExports->execute(
                $report,
                ReportContext::create(
                    ['course_id' => $this->courseId],
                    $this->actor,
                    VisibilityScope::forCourse($this->courseId),
                ),
                'csv',
                'notification-link',
                ReportScopeDigest::of(VisibilityScope::forCourse($this->courseId)),
                7,
                $recipients,
                null,
                DeliveryContext::RENDER_AS_AUTHOR,
            );
        } catch (ValidationException $refused) {
            $authorRefused = (string) ($refused->errors['render_as'] ?? '');
        }

        $this->expect(
            str_contains($authorRefused, 'one organisation only'),
            'an author render is refused outright once the audience spans more than one organisation',
            $authorRefused === '' ? 'the crossing was allowed' : $authorRefused,
        );

        $attachRefused = '';

        try {
            $this->kernel->reportExports->execute(
                $report,
                ReportContext::create(
                    ['course_id' => $this->courseId],
                    $this->actor,
                    VisibilityScope::forCourse($this->courseId),
                ),
                'csv',
                SmokeAttachingDelivery::KEY,
                ReportScopeDigest::of(VisibilityScope::forCourse($this->courseId)),
                7,
                $recipients,
                null,
                DeliveryContext::RENDER_AS_RECIPIENT,
            );
        } catch (ValidationException $refused) {
            $attachRefused = (string) ($refused->errors['delivery'] ?? '');
        }

        $this->expect(
            str_contains($attachRefused, 'email attachment') && $attaching->reached === [],
            'a row per person report is not attached across an organisation boundary',
            $attachRefused === '' ? 'the attachment was allowed' : $attachRefused,
        );

        $after = $this->ports->database->count(Query::from('report_runs'));

        $this->expect(
            $after === $before,
            'neither refusal opened a run row, so no artefact of personal data exists that nobody may receive',
            $before . ' before, ' . $after . ' after',
        );
    }

    /**
     * Puts one of the two recipients inside an organisation of this section's
     * own, so the recipient set spans at least two whatever the host already
     * holds.
     *
     * It writes rows rather than calling the organisation use cases, because
     * the actor may already be a member of the organisation the harness made
     * and a second membership through the use case is a conflict. Everything
     * here is inside the enclosing transaction and goes away with it.
     */
    private function seatOneRecipientApart(): void
    {
        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');
        $slug = self::MARKER . '-apart-' . bin2hex(random_bytes(6));

        $organisationId = $this->ports->database->insert('organisations', [
            'name' => 'Gradebook contract, the far side',
            'slug' => $slug,
            'type' => 'organisation',
            'state' => 'active',
            'depth' => 0,
            'root_id' => 0,
            'revision' => 1,
            'invitation_match_key' => 'strict_email',
            'retention_policy_version' => 1,
            'created_by' => $this->actor->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->ports->database->update(
            'organisations',
            ['root_id' => $organisationId],
            ['id' => $organisationId],
        );

        $this->ports->database->insert('org_memberships', [
            'organisation_id' => $organisationId,
            'user_id' => $this->learnerId,
            'state' => 'active',
            'active_key' => $organisationId . ':' . $this->learnerId,
            'is_primary' => 0,
            'directory_managed' => 0,
            'provisioned_by' => 'manual',
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // -----------------------------------------------------------------------
    // Cleanup
    // -----------------------------------------------------------------------

    /** A file has no transaction, so every artefact is removed by path. */
    private function removeEveryArtefact(): void
    {
        foreach ($this->artefacts as $path) {
            try {
                if ($this->ports->storage->exists($path)) {
                    $this->ports->storage->delete($path);
                }
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not remove the artefact ' . $path . ': ' . $failure->getMessage();
            }
        }
    }

    /**
     * Checked rather than assumed: a host whose transaction support quietly did
     * nothing would otherwise leave a schedule running nightly on a live site
     * and report a clean run.
     */
    private function assertNothingSurvived(): void
    {
        $left = [];

        foreach (self::MARKED_BY as $table => $column) {
            $count = $this->countQuietly(Query::from($table)->where($column, 'LIKE', self::MARKER . '%'));

            if ($count > 0) {
                $left[] = $count . ' ' . $table;
            }
        }

        if ($this->lessonId > 0 && $this->countQuietly(Query::from('lessons')->where('id', '=', $this->lessonId)) > 0) {
            $left[] = '1 lessons';
        }

        if ($left === []) {
            $this->facts[] = 'ok: the transaction rolled back and nothing this section wrote survived';

            return;
        }

        $this->failures[] = 'the contract run left ' . implode(', ', $left)
            . ' row(s) behind: transactional() did not roll back';

        // The transaction guarantee is already known to be absent, so the rows
        // are removed rather than left on a live site. Only the marked ones:
        // this run updated no row that was already there.
        try {
            if ($this->lessonId > 0) {
                $this->ports->database->delete('lessons', ['id' => $this->lessonId]);
            }
        } catch (\Throwable) {
            // Reported already; a failed cleanup must not mask the failure.
        }
    }

    private function countQuietly(Query $query): int
    {
        try {
            return $this->ports->database->count($query);
        } catch (\Throwable) {
            // A table this build does not have is not a survivor.
            return 0;
        }
    }

    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value);

        return $encoded === false ? gettype($value) : $encoded;
    }

    private function expect(bool $condition, string $what, string $evidence): void
    {
        if ($condition) {
            $this->facts[] = 'ok: ' . $what;

            return;
        }

        $this->failures[] = $what . ': ' . $evidence;
    }
}
