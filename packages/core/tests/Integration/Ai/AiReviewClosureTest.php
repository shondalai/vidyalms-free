<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRunPipeline;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRunRequest;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunState;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftKinds;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRenderMode;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\CancellationSignal;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\BridgeCallingTask;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\ItemContentFactProbe;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\ScriptedAiTask;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\ShortRetentionTask;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\SignalReadingTask;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\WindowedProbePolicy;

/**
 * The R8 review's findings, each as the case that fails before its fix.
 *
 * They are one class because they share a fixture and a set-up: a site with
 * the four levels of enablement turned on is what every one of them needs,
 * and writing it once is what stopped the earlier cases from each inventing a
 * slightly different site.
 *
 * Nothing here reaches a model. The only registered bridge on a free build is
 * the null one, which computes its answer from the request in front of it.
 */
final class AiReviewClosureTest extends TestCase
{
    private const OTHER_LEARNERS = [51, 52];

    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    /* ── Containment: the subject and the scope ─────────────────────── */

    /**
     * A teacher of one course may not point a run at another course's item.
     *
     * Owen teaches course 2 and nothing else. Naming question 1, which belongs
     * to course 1, used to answer succeeded: the run read three learners'
     * answer text, wrote a draft holding it, stamped the draft with course 2,
     * and the draft list handed it straight back to him.
     */
    public function testATeacherCannotPointARunAtAnotherCoursesItem(): void
    {
        $this->enableAi([$this->fixture->outsider()]);
        $this->seedAnswers();
        $this->addRoute('grading.group_equivalent_answers');

        $run = $this->startRun($this->fixture->outsider(), [
            'task_key' => 'grading.group_equivalent_answers',
            'scope_type' => 'course',
            'scope_id' => $this->fixture->otherCourseId,
            'course_id' => $this->fixture->otherCourseId,
            'subject_type' => 'question',
            'subject_id' => $this->fixture->questionId,
        ], 'outsider-1');

        self::assertSame(200, $run->status);
        self::assertNotNull($run->data);
        self::assertSame('refused', $run->data['state']);
        self::assertSame('ai.policy.subject_out_of_scope', $run->data['error_message']);
        self::assertSame(0, $this->rows('ai_drafts'), 'nothing was drafted, because nothing was read');

        $drafts = $this->fixture->call(
            'GET',
            '/ai/drafts',
            $this->fixture->outsider(),
            [],
            ['course_id' => (string) $this->fixture->otherCourseId],
        );
        self::assertSame(0, $drafts->data['total'] ?? -1);
    }

    /**
     * A grouping run over three learners writes three subject rows, and all
     * three learners can see it.
     *
     * The one many-cardinality fact is keyed by attempt, which the runner's
     * convention could not read a person out of, so the run wrote no subject
     * row at all and answered "no runs" to every learner in it.
     */
    public function testAGroupingRunNamesEveryLearnerItReadAndAllOfThemCanSeeIt(): void
    {
        $this->enableAi([$this->fixture->teacher()]);
        $this->seedAnswers();
        $this->addRoute('grading.group_equivalent_answers');

        $run = $this->startRun($this->fixture->teacher(), [
            'task_key' => 'grading.group_equivalent_answers',
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'subject_type' => 'question',
            'subject_id' => $this->fixture->questionId,
        ], 'grouping-1');

        self::assertNotNull($run->data);
        self::assertSame('succeeded', $run->data['state'], json_encode($run->data, JSON_THROW_ON_ERROR));

        $subjects = $this->fixture->env->database->selectAll(
            Query::from('ai_run_subjects')->select(['user_id'])->orderBy('user_id'),
        );
        self::assertSame(
            [['user_id' => ManagementFixture::LEARNER_ID], ['user_id' => 51], ['user_id' => 52]],
            $subjects,
            'one subject row per learner whose answer the run read',
        );

        foreach ([ManagementFixture::LEARNER_ID, ...self::OTHER_LEARNERS] as $learner) {
            $activity = $this->fixture->call('GET', '/me/ai-activity', Principal::user($learner));

            self::assertSame(200, $activity->status);
            self::assertNotNull($activity->data);
            self::assertSame(1, $activity->data['total'], 'learner ' . $learner . ' is told about the run');
            self::assertSame(
                'pending',
                $activity->data['items'][0]['outcome'],
                'and what became of the output, which is a different fact from the run state: the '
                . 'grouping proposal is written and nobody has confirmed it yet',
            );
        }
    }

    /* ── Containment: the gates ─────────────────────────────────────── */

    /**
     * Omitting the optional placement key does not walk around level two.
     *
     * The same body twice on one site with one panel disabled: naming the
     * panel refused and omitting the field succeeded and wrote the draft, so
     * the gate was keyed on the absence of a field the caller controls.
     */
    public function testOmittingThePlacementKeyDoesNotWalkAroundTheSecondLevel(): void
    {
        $this->enableAi([$this->fixture->teacher()], ['gradebook_grouping']);
        $this->seedAnswers();
        $this->addRoute('grading.group_equivalent_answers');

        $body = [
            'task_key' => 'grading.group_equivalent_answers',
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'subject_type' => 'question',
            'subject_id' => $this->fixture->questionId,
        ];

        $named = $this->startRun($this->fixture->teacher(), $body + ['placement_key' => 'gradebook_grouping'], 'named');
        $unnamed = $this->startRun($this->fixture->teacher(), $body, 'unnamed');

        self::assertNotNull($named->data);
        self::assertNotNull($unnamed->data);
        self::assertSame('refused', $named->data['state']);
        self::assertSame('refused', $unnamed->data['state'], 'the two answer the same way');
        self::assertSame('ai.policy.placement_disabled', $unnamed->data['error_message']);
        self::assertSame(0, $this->rows('ai_drafts'));
    }

    /**
     * A denial names the kind of gate that raised it.
     *
     * Every policy denial was `ceiling_exceeded`, so a stopped site, an
     * unconsented person, a disabled panel and a subject out of scope were all
     * counted in the budget bucket and all inherited the budget retry
     * decision.
     */
    public function testEachShippedGateNamesItsOwnRefusalKind(): void
    {
        $body = [
            'task_key' => 'check.schema_validity',
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
        ];

        // Nothing published: not_configured, which is a thing an administrator
        // does and never a budget.
        $unpublished = $this->startRun($this->fixture->teacher(), $body, 'kinds-1');
        self::assertNotNull($unpublished->data);
        self::assertSame('not_configured', $unpublished->data['refusal']['kind']);
        self::assertSame('ai.policy.none_published', $unpublished->data['error_message']);

        // Published and accepted, but no route: still not_configured.
        $this->enableAi([$this->fixture->teacher()]);
        $noRoute = $this->startRun($this->fixture->teacher(), $body, 'kinds-2');
        self::assertNotNull($noRoute->data);
        self::assertSame('not_configured', $noRoute->data['refusal']['kind']);
        self::assertSame('ai.policy.no_route', $noRoute->data['error_message']);

        // A subject in another course: input_rejected, because it is a
        // statement about the request as composed.
        $this->addRoute('grading.group_equivalent_answers');
        $outOfScope = $this->startRun($this->fixture->outsider(), [
            'task_key' => 'grading.group_equivalent_answers',
            'scope_type' => 'course',
            'scope_id' => $this->fixture->otherCourseId,
            'course_id' => $this->fixture->otherCourseId,
            'subject_type' => 'question',
            'subject_id' => $this->fixture->questionId,
        ], 'kinds-3');
        self::assertNotNull($outOfScope->data);
        self::assertSame('input_rejected', $outOfScope->data['refusal']['kind']);
    }

    /* ── The ledger ─────────────────────────────────────────────────── */

    /**
     * Two refusals at one clock tick are two rows, and neither is a 500.
     *
     * The refusal row's key was a hash of the task and the clock to the
     * second, and the insert was unguarded, so the second refusal in a second
     * raised a unique-constraint violation out of the pipeline: a 500 on the
     * path whose whole point is that a refusal is an ordinary outcome.
     */
    public function testTwoRefusalsAtOneClockTickAreTwoLedgerRows(): void
    {
        $body = [
            'task_key' => 'check.schema_validity',
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
        ];

        $first = $this->startRun($this->fixture->teacher(), $body, 'refuse-1');
        $second = $this->startRun($this->fixture->teacher(), $body, 'refuse-2');
        $third = $this->startRun($this->fixture->teacher(), $body, 'refuse-3');

        self::assertSame(200, $first->status);
        self::assertSame(200, $second->status, 'the second refusal is a refusal and not an internal error');
        self::assertSame(200, $third->status);
        self::assertSame(3, $this->rows('ai_runs'), 'every refusal reaches the ledger');
    }

    /**
     * Two identical previews in one second both answer a manifest.
     *
     * The run key folded the clock to the second, so the second estimate of
     * the same subject collided with the first and was refused as a
     * configuration fault. The dry run is the privacy artefact, and refusing
     * it for the rest of the second is refusing the control the design sells.
     */
    public function testTwoIdenticalPreviewsInOneSecondBothAnswer(): void
    {
        $this->enableAi([$this->fixture->manager()]);
        $this->addRoute('check.schema_validity');

        $body = [
            'task_key' => 'check.schema_validity',
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
        ];

        $first = $this->fixture->call('POST', '/ai/estimate', $this->fixture->manager(), $body);
        $second = $this->fixture->call('POST', '/ai/estimate', $this->fixture->manager(), $body);

        self::assertNotNull($first->data);
        self::assertNotNull($second->data);
        self::assertTrue($first->data['allowed'], json_encode($first->data, JSON_THROW_ON_ERROR));
        self::assertTrue($second->data['allowed'], json_encode($second->data, JSON_THROW_ON_ERROR));
        self::assertNotSame($first->data['run_id'], $second->data['run_id']);
    }

    /**
     * The route the run selected is on the row, and so is the bridge it
     * resolved.
     *
     * `route_id` was assigned nowhere, `bridge_key` was the literal string
     * `null` and `model` was never set, so a site with an enabled route naming
     * a real bridge still recorded that it had used none.
     */
    public function testTheSelectedRouteAndItsBridgeAreStampedOnTheRun(): void
    {
        $this->enableAi([$this->fixture->teacher()]);
        $this->addRoute('check.schema_validity');

        $run = $this->startRun($this->fixture->teacher(), [
            'task_key' => 'check.schema_validity',
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
        ], 'route-1');

        self::assertNotNull($run->data);
        self::assertSame('succeeded', $run->data['state'], json_encode($run->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($run->data['route_id'], 'the run names the route it selected');
        self::assertSame('null', $run->data['bridge_key']);
    }

    /**
     * A task that asks for a call reaches the free bridge, and the attempt row
     * records what the record class carries.
     *
     * The ledger dropped the position, the upstream reference and the
     * auto-retry flag, and wrote the dropped schema constraints of a
     * SUCCEEDED attempt into `error_message`.
     */
    public function testTheAttemptRowCarriesWhatTheRecordClassCarries(): void
    {
        $task = new BridgeCallingTask();
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAi([$this->fixture->teacher()]);
        $this->addRoute($task->key());

        $run = $this->startRun($this->fixture->teacher(), [
            'task_key' => $task->key(),
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
        ], 'attempt-1');

        self::assertNotNull($run->data);
        self::assertSame('succeeded', $run->data['state'], json_encode($run->data, JSON_THROW_ON_ERROR));
        self::assertSame(1, $task->calls);
        self::assertTrue($task->sawBridge);

        $attempt = $this->fixture->env->database->selectOne(
            Query::from('ai_run_attempts')->where('run_id', '=', (int) $run->data['id']),
        );

        self::assertNotNull($attempt);
        self::assertSame('null', $attempt['bridge_key']);
        self::assertSame(1, (int) $attempt['success']);
        self::assertNull($attempt['error_message'], 'a succeeded attempt carries no error message');
        self::assertNotNull($attempt['provider_request_id'], 'the upstream reference has its own column');
        self::assertSame(0, (int) $attempt['position']);
    }

    /**
     * The ledger row is the record of what left, and it was the record of
     * something else.
     *
     * The reserved row carries the manifest of the payload the pipeline
     * resolved, written before the task runs. A model-backed task holds the
     * bridge caller and may hand it a payload of its own, and the caller
     * redacts and sends exactly that. `lastManifest()` held the truth and its
     * own docblock said the record and the wire cannot disagree; nothing
     * outside two unit tests read it, so they could.
     */
    public function testTheRunRowRecordsTheManifestOfWhatActuallyLeft(): void
    {
        $task = new BridgeCallingTask('test.calls_the_bridge.manifest');
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAi([$this->fixture->teacher()]);
        $this->addRoute($task->key());

        $run = $this->startRun($this->fixture->teacher(), [
            'task_key' => $task->key(),
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
        ], 'manifest-1');

        self::assertNotNull($run->data);
        self::assertSame('succeeded', $run->data['state'], json_encode($run->data, JSON_THROW_ON_ERROR));

        $row = $this->fixture->env->database->selectOne(
            Query::from('ai_runs')->where('id', '=', (int) $run->data['id']),
        );

        self::assertNotNull($row);

        $stored = json_decode((string) $row['redaction_manifest'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);

        $fields = $stored['data']['fields'] ?? $stored['fields'] ?? null;

        self::assertSame(
            ['lesson:1'],
            $fields,
            'the row names the span the task actually sent, not the one the pipeline resolved',
        );
    }

    /**
     * Five columns `ai_runs` declares, one of them indexed for the
     * reconciliation join, were written as literal nulls on every successful
     * run because nothing between the caller and the settle stage held them.
     */
    public function testTheRunRowCarriesTheProvenanceOfTheCallItPaidFor(): void
    {
        $task = new BridgeCallingTask('test.calls_the_bridge.provenance');
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAi([$this->fixture->teacher()]);
        $this->addRoute($task->key());

        $run = $this->startRun($this->fixture->teacher(), [
            'task_key' => $task->key(),
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
        ], 'provenance-1');

        self::assertNotNull($run->data);

        $row = $this->fixture->env->database->selectOne(
            Query::from('ai_runs')->where('id', '=', (int) $run->data['id']),
        );

        self::assertNotNull($row);
        self::assertNotNull(
            $row['provider_request_id'],
            'the column the reconciliation sweep joins on is written',
        );

        $attempt = $this->fixture->env->database->selectOne(
            Query::from('ai_run_attempts')->where('run_id', '=', (int) $run->data['id']),
        );

        self::assertNotNull($attempt);
        self::assertSame(
            $attempt['provider_request_id'],
            $row['provider_request_id'],
            'the run and its attempt name one call',
        );
    }

    /* ── The stop ───────────────────────────────────────────────────── */

    /**
     * The stop takes effect on the stage it names, not on the next one.
     *
     * The loop halted the run and then fell through to the stage's own
     * handle(), so a stop observed before IterateRoute still called the task
     * and a stop observed before WriteDrafts still wrote its drafts.
     */
    public function testAStopObservedBeforeAStageStopsThatStage(): void
    {
        $this->enableAiRows((new ScriptedAiTask())->key());
        $task = (new ScriptedAiTask())->willDraft(['stem' => 'Never written']);
        $this->fixture->kernel->registries->aiTasks->register($task);

        $observations = 0;
        // Cancelled from the seventh observation, which is the boundary in
        // front of the stage that runs the task.
        $stop = CancellationSignal::from(static function () use (&$observations): bool {
            ++$observations;

            return $observations >= 7;
        });

        $report = $this->fixture->kernel->aiPipeline->run($this->pipelineRequest($task->key()), $stop);

        self::assertSame(AiRunState::Cancelled, $report->state);
        self::assertSame(0, $task->calls, 'the stopped stage did not run');
        self::assertSame(0, $this->rows('ai_drafts'));
    }

    /* ── Extensibility at the seams ─────────────────────────────────── */

    /**
     * A placement listing a scored or gated fillable field is refused at
     * registration, exactly as a task with one in its output schema is.
     *
     * The task half was closed and the placement half was not, which is
     * precisely the pair `ReservedFields` names as the failure it exists to
     * stop.
     */
    public function testAPlacementListingAScoredFieldIsRefusedAtRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reserved fillable field/');

        $this->fixture->kernel->registries->aiPlacements->register(
            new class implements AiPlacementInterface {
                public function key(): string
                {
                    return 'acme_score_fill';
                }

                public function label(): string
                {
                    return 'acme';
                }

                public function surfaceKey(): string
                {
                    return 'gradebook';
                }

                public function anchor(): string
                {
                    return 'gradebook.answer.field';
                }

                public function renderMode(): AiRenderMode
                {
                    return AiRenderMode::FieldFill;
                }

                public function accepts(
                    \Shondalai\VidyaLMS\Core\Registry\Ai\TaskDescriptor $task,
                    \Shondalai\VidyaLMS\Core\Registry\Context\PlacementContext $context,
                ): bool {
                    return true;
                }

                public function fillableFields(): array
                {
                    return ['score', 'points_awarded', 'passed'];
                }

                public function scopeLevel(): string
                {
                    return 'item';
                }

                public function disclosure(): \Shondalai\VidyaLMS\Core\Registry\Ai\AiDisclosure
                {
                    return \Shondalai\VidyaLMS\Core\Registry\Ai\AiDisclosure::of(
                        \Shondalai\VidyaLMS\Core\Registry\Ai\AiDisclosureMode::EveryInteraction,
                        'a',
                        'b',
                        'c',
                    );
                }

                public function offersFor(
                    \Shondalai\VidyaLMS\Core\Registry\Context\PlacementContext $context,
                ): array {
                    return [];
                }
            },
        );
    }

    /**
     * An acceptor declaring a grading extension point is refused at
     * registration, and a draft naming one is refused at the last door.
     *
     * Golden rule two was asserted over the registered free population only,
     * which by construction cannot break it.
     */
    public function testTheForbiddenTargetsAreRefusedAtRegistrationAndAtAccept(): void
    {
        self::assertSame(
            ['grading', 'availability', 'credential', 'enrolment'],
            \Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftAcceptorRegistry::FORBIDDEN_TARGETS,
        );

        $this->enableAiRows((new ScriptedAiTask('test.targets_grading', 'grading', 'manual'))->key());
        $task = (new ScriptedAiTask('test.targets_grading', 'grading', 'manual'))
            ->willDraft(['feedback_to_learner' => 'never written']);
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run($this->pipelineRequest($task->key()));

        self::assertSame(AiRunState::Succeeded, $report->state);
        self::assertCount(1, $report->draftIds);

        $accepted = $this->fixture->call(
            'POST',
            '/ai/drafts/' . $report->draftIds[0] . '/accept',
            $this->fixture->teacher(),
            [],
            [],
            'accept-grading',
        );

        self::assertSame(409, $accepted->status);
        self::assertNotNull($accepted->data);
        self::assertStringContainsString('grading', (string) ($accepted->data['detail'] ?? ''));
    }

    /**
     * A draft carries the payload version its producer declared.
     *
     * The repository wrote a hardcoded 1 into the envelope and the mirrored
     * column, which made the question acceptor's promise that a draft carries
     * its definition version unkeepable.
     */
    public function testADraftCarriesTheVersionItsProducerDeclared(): void
    {
        $this->enableAiRows((new ScriptedAiTask())->key());
        $task = (new ScriptedAiTask())->willDraftCandidate(AiDraftCandidate::create(
            AiDraftKinds::QUESTION,
            'question_type',
            ['prompt' => 'Describe an oolong.'],
            'short_answer',
            [],
            0,
            null,
            3,
        ));
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run($this->pipelineRequest($task->key()));

        self::assertCount(1, $report->draftIds);

        $draft = $this->fixture->env->database->selectOne(
            Query::from('ai_drafts')->where('id', '=', $report->draftIds[0]),
        );

        self::assertNotNull($draft);
        self::assertSame(3, (int) $draft['payload_version']);
        self::assertStringContainsString('"_v":3', (string) $draft['payload']);
    }

    /* ── Configuration that used to be a constant ───────────────────── */

    /**
     * A ceiling a site writes is a ceiling the run meets.
     *
     * Every spend and rate ceiling read the caller's own default, so all six
     * were pinned to their constants and a site writing a per-scope call
     * limit of one saw four consecutive runs succeed.
     */
    public function testAConfiguredCeilingBindsTheSecondRun(): void
    {
        $this->enableAi([$this->fixture->teacher()]);
        $this->addRoute('check.schema_validity');

        $written = $this->fixture->call('PUT', '/manage/ai/enablement', $this->fixture->manager(), [
            'ceilings' => ['ceiling.per_scope_calls' => 1],
        ], [], 'ceiling-1');

        self::assertSame(200, $written->status);
        self::assertSame(['ceiling.per_scope_calls' => 1], $written->data['ceilings'] ?? null);

        $body = [
            'task_key' => 'check.schema_validity',
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
        ];

        $first = $this->startRun($this->fixture->teacher(), $body, 'ceiling-run-1');
        $second = $this->startRun($this->fixture->teacher(), $body, 'ceiling-run-2');

        self::assertNotNull($first->data);
        self::assertNotNull($second->data);
        self::assertSame('succeeded', $first->data['state']);
        self::assertSame(
            'reserved',
            $second->data['state'],
            'a windowed ceiling defers rather than denying, because not now is a real answer',
        );
    }

    /**
     * A policy version with an empty part cannot be activated.
     *
     * The eight-part validator was called by no write path, so a site could
     * activate a one-character body and then record every person's acceptance
     * of a document that discloses nothing.
     */
    public function testAPolicyVersionWithAnEmptyPartCannotBeActivated(): void
    {
        $created = $this->fixture->call('POST', '/manage/ai/policies', $this->fixture->manager(), [
            'title' => 'Half a policy',
            'body' => ['based_on_external_models' => 'Only the first part is written.'],
            'retention_days' => 400,
        ], [], 'half-1');

        self::assertSame(201, $created->status);
        self::assertNotNull($created->data);
        self::assertCount(7, $created->data['missing_parts'], 'a draft may be incomplete and says which parts are');

        $activated = $this->fixture->call(
            'PATCH',
            '/manage/ai/policies/' . (int) $created->data['version'],
            $this->fixture->manager(),
            ['state' => 'active'],
            [],
            'half-activate',
        );

        self::assertSame(409, $activated->status);
        self::assertNotNull($activated->data);
        self::assertSame('ai.policy.incomplete', $activated->data['reason'] ?? null);
    }

    /**
     * The route table says what the route does with what it is sent.
     *
     * The capability existed and nothing probed it, so the screen could not
     * show whether a configured route retained input or trained on it.
     */
    public function testTheRouteTableRendersTheBridgesDataHandling(): void
    {
        $this->enableAi([$this->fixture->manager()]);
        $this->addRoute('check.schema_validity');

        $routes = $this->fixture->call('GET', '/manage/ai/routes', $this->fixture->manager());

        self::assertSame(200, $routes->status);
        self::assertNotNull($routes->data);
        self::assertNotSame([], $routes->data['items']);

        $handling = $routes->data['items'][0]['data_handling'];

        self::assertTrue($handling['declared'], 'the free bridge declares, because nothing leaves the site');
        self::assertFalse($handling['trains_on_input']);
        self::assertSame('local', $handling['processing_region']);
    }

    /* ── The personal page ──────────────────────────────────────────── */

    /**
     * The activity view carries the four personal-page rules.
     *
     * Both adapters reimplemented them in prose and in code and the route
     * itself set none of them, while the credential handlers next door set
     * the fourth by hand.
     */
    public function testTheActivityViewCarriesThePersonalPageHeaders(): void
    {
        $response = $this->fixture->call('GET', '/me/ai-activity', $this->fixture->learner());

        self::assertSame(200, $response->status);
        self::assertSame('private, no-store', $response->headers['Cache-Control'] ?? null);
        self::assertSame('no-referrer', $response->headers['Referrer-Policy'] ?? null);
        self::assertSame('noindex, nofollow, noarchive', $response->headers['X-Robots-Tag'] ?? null);
    }

    /**
     * A feedback draft can be accepted, and accepting one moves no mark.
     *
     * The acceptor passed a null mark to the manual grading use case, which
     * reads that as "you forgot the mark" and throws, so every gradebook
     * feedback draft was permanently unacceptable AND the 422 a reviewer got
     * invited them to supply the one thing this design forbids beside the
     * generated text.
     */
    public function testAFeedbackDraftIsAcceptedAndMovesNoMark(): void
    {
        $this->seedAnswers();
        $before = $this->fixture->env->database->selectOne(Query::from('attempts')->where('id', '=', 1));
        self::assertNotNull($before);

        $artefact = $this->fixture->kernel->registries->aiDraftAcceptors->get('feedback_text')->accept(
            \Shondalai\VidyaLMS\Core\Registry\Ai\AiDraft::of(
                1,
                1,
                'feedback_text',
                [
                    'attempt_id' => 1,
                    'question_id' => $this->fixture->questionId,
                    'feedback_to_learner' => 'Say more about oxidation.',
                ],
                'ai_draft_acceptor',
                'feedback_text',
            ),
            \Shondalai\VidyaLMS\Core\Registry\Context\AiAcceptContext::create(
                $this->fixture->teacher(),
                'ai_draft_acceptor',
                'feedback_text',
                'run:1',
                new \DateTimeImmutable('2026-01-15 12:00:00'),
            ),
        );

        self::assertSame('attempt_grade', $artefact->entityType);

        $after = $this->fixture->env->database->selectOne(Query::from('attempts')->where('id', '=', 1));
        self::assertNotNull($after);
        self::assertSame($before['status'], $after['status'], 'the attempt is not closed by a comment');
        self::assertSame($before['score'], $after['score']);
        self::assertSame($before['percentage'], $after['percentage']);

        $grade = $this->fixture->env->database->selectOne(Query::from('attempt_grades')->where('attempt_id', '=', 1));
        self::assertNotNull($grade);
        self::assertSame('Say more about oxidation.', (string) $grade['feedback']);
        self::assertSame('notscored', (string) $grade['score_status'], 'a comment is not a mark');
    }

    /* ── Grounding, retention and the task context ──────────────────── */

    /**
     * A grounding key the request names resolves into the payload, so the
     * manifest can say the chunk travelled.
     *
     * `AiGroundingIndexer` wrote `ai_grounding_chunks` and nothing in the whole
     * product read the table: the payload's chunk list was always empty, the
     * manifest could never name a grounding source, and the strict redactor's
     * chunk branch, which is the one that applies the trust marking, was dead
     * on both entry paths.
     */
    public function testAGroundingKeyReachesThePayloadAndTheManifest(): void
    {
        $this->enableAi([$this->fixture->teacher()]);
        $this->addRoute('check.citation_coverage');
        $chunkKey = $this->seedGroundingChunk();

        $run = $this->startRun($this->fixture->teacher(), [
            'task_key' => 'check.citation_coverage',
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
            'grounding_keys' => [$chunkKey],
        ], 'grounding-1');

        self::assertNotNull($run->data);
        self::assertSame('succeeded', $run->data['state'], json_encode($run->data, JSON_THROW_ON_ERROR));
        self::assertContains(
            'lesson:' . $this->fixture->lessonId,
            $run->data['redaction_manifest'] ?? [],
            'the chunk is on the wire, so the manifest names where it came from',
        );
    }

    /**
     * A task may shorten how long its own drafts are kept, and may not
     * lengthen it.
     *
     * Retention was three constants and no AI record class declared a lawful
     * basis at all, so a vendor draft kind whose payload is a learner's
     * submission could not be kept for less time than a glossary suggestion.
     */
    public function testATaskCanShortenItsOwnDraftRetentionAndCannotLengthenIt(): void
    {
        $this->enableAiRows('test.short_retention');
        $task = new ShortRetentionTask();
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run($this->pipelineRequest($task->key()));

        self::assertCount(1, $report->draftIds);

        $draft = $this->fixture->env->database->selectOne(
            Query::from('ai_drafts')->where('id', '=', $report->draftIds[0]),
        );

        self::assertNotNull($draft);
        self::assertSame(
            '2026-01-22 10:00:00',
            (string) $draft['retain_until'],
            'seven days, which is what the task asked for and shorter than the ninety the site keeps',
        );
    }

    /**
     * The task is handed the run's own stop signal.
     *
     * The member was seated a wave early precisely so a long-running task
     * could be written to ask, and both production call sites stopped at the
     * twelfth positional argument, so every task was told to continue for ever.
     */
    public function testTheTaskIsHandedTheRunsOwnStopSignal(): void
    {
        $this->enableAiRows('test.reads_the_signal');
        $task = new SignalReadingTask();
        $this->fixture->kernel->registries->aiTasks->register($task);

        // The signal answers false at every stage boundary and true when the
        // task itself asks, so the assertion is about WHOSE signal the task
        // was handed rather than about a count of stages.
        $askedFromInsideTheTask = false;
        $signal = CancellationSignal::from(
            static function () use ($task, &$askedFromInsideTheTask): bool {
                if (!$task->entered) {
                    return false;
                }

                $askedFromInsideTheTask = true;

                return true;
            },
        );

        $this->fixture->kernel->aiPipeline->run($this->pipelineRequest($task->key()), $signal);

        self::assertTrue($askedFromInsideTheTask, 'the run\'s own signal is what the task was handed');
        self::assertTrue($task->sawCancellation, 'and it answered the truth rather than never()');
    }

    /**
     * A definition key that carries correctness is removed at any depth.
     *
     * `withoutSecrets()` unset the top level only. The shipped six all keep
     * their key at the top so nothing leaked; a registered type nesting one, a
     * per-option `correct` or a per-blank `accepted`, leaked it straight
     * through `item.stem_redacted`, which exists precisely because the
     * learner-facing explanation task once carried the key.
     */
    public function testANestedSecretKeyIsRemovedFromTheRedactedStem(): void
    {
        $projection = ItemContentFactProbe::withoutKeys(
            [
                'prompt' => 'Pick the oolongs.',
                'options' => [
                    ['key' => 'a', 'label' => 'Tieguanyin', 'correct' => true],
                    ['key' => 'b', 'label' => 'Sencha', 'correct' => false],
                ],
                'blanks' => ['one' => ['accepted' => ['oolong']]],
            ],
            ['correct', 'accepted'],
        );

        $encoded = json_encode($projection, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('correct', $encoded);
        self::assertStringNotContainsString('accepted', $encoded);
        self::assertStringContainsString('Tieguanyin', $encoded, 'the rest of the stem still travels');
    }

    /* ── The reservation, the tenant and the refusal kinds ──────────── */

    /**
     * A windowed ceiling is asked inside the transaction that writes the
     * reserved row, and a ceiling reached there stops the write.
     *
     * The stage's own docblock and the standards page both said so and neither
     * was true: the fold was stage two, the reservation stage six, and the
     * transaction wrapped the insert alone, which is the fan-out failure the
     * docblock says it prevents.
     */
    public function testAWindowedCeilingIsAskedInsideTheReservingTransaction(): void
    {
        $this->enableAiRows('test.scripted');
        $task = new ScriptedAiTask();
        $this->fixture->kernel->registries->aiTasks->register($task);

        $probe = new WindowedProbePolicy($this->fixture->env->database);
        $this->fixture->kernel->registries->aiPolicies->register($probe);

        $this->fixture->kernel->aiPipeline->run($this->pipelineRequest($task->key()));

        self::assertCount(2, $probe->depths, 'asked once in the fold and once in the reservation');
        self::assertSame(0, $probe->depths[0], 'the fold is stage two and opens nothing');
        self::assertGreaterThan(
            0,
            $probe->depths[1],
            'and the re-read happens inside the transaction that writes the reserved row',
        );
    }

    /**
     * A ceiling reached between the fold and the reservation stops the run
     * before the row is written.
     */
    public function testACeilingReachedDuringTheReservationWritesNoReservedRow(): void
    {
        $this->enableAiRows('test.scripted');
        $task = new ScriptedAiTask();
        $this->fixture->kernel->registries->aiTasks->register($task);

        // Allows in the fold and denies in the reservation, which is what a
        // concurrent run taking the last of the headroom looks like.
        $this->fixture->kernel->registries->aiPolicies->register(new WindowedProbePolicy(
            $this->fixture->env->database,
            \Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision::deny(
                [\Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision::TARGET_AI_RUN],
                'ai.policy.test_ceiling',
            ),
        ));

        $report = $this->fixture->kernel->aiPipeline->run($this->pipelineRequest($task->key()));

        self::assertSame(AiRunState::Refused, $report->state);
        self::assertSame(0, $task->calls, 'the task never ran');
        self::assertSame(0, $this->rows('ai_drafts'));
    }

    /**
     * An organisation-scoped run mints a scope through the resolver and stamps
     * the tenant, and an organisation this caller cannot reach is refused.
     *
     * The entry handler passed null for the scope on both entry paths while
     * accepting `scope_type: organisation` with any id, so the ledger read
     * `organisation_id: null`, the tenant's spend was counted site-wide and
     * every read narrowed on the organisation missed the run.
     */
    public function testAnOrganisationScopedRunStampsItsTenantAndRefusesAStranger(): void
    {
        $organisationId = (int) $this->fixture->kernel->organisations->create(
            $this->fixture->manager(),
            ['name' => 'North', 'slug' => 'north'],
        )['id'];

        $this->enableAi([$this->fixture->manager()]);
        $this->addRoute('check.schema_validity');

        $body = [
            'task_key' => 'check.schema_validity',
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'scope_type' => 'organisation',
        ];

        $stamped = $this->startRun($this->fixture->manager(), $body + [
            'scope_id' => $organisationId,
        ], 'org-1');

        self::assertSame(200, $stamped->status, json_encode($stamped->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($stamped->data);
        self::assertSame($organisationId, $stamped->data['organisation_id']);

        $stranger = $this->startRun($this->fixture->manager(), $body + ['scope_id' => 9999], 'org-2');

        self::assertSame(404, $stranger->status, 'an organisation nobody can reach is one refusal, not a run');
        self::assertSame(1, $this->rows('ai_runs'), 'and it writes no ledger row at all');
    }

    /**
     * A draft naming no acceptor target refuses `not_configured`, not
     * `schema_invalid`.
     *
     * Nothing about the schema was invalid: the task was mis-declared, and
     * `schema_invalid` is the kind the item review screen reads as a decoding
     * failure.
     */
    public function testADraftNamingNoTargetRefusesAsAConfigurationFault(): void
    {
        $this->enableAiRows('test.untargeted');
        $task = (new ScriptedAiTask('test.untargeted', '', null))
            ->willDraft(['prompt' => 'Never written']);
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run($this->pipelineRequest($task->key()));

        self::assertSame(AiRunState::Refused, $report->state);
        self::assertNotNull($report->refusal);
        self::assertSame('ai.draft.untargeted', $report->refusal->messageKey);
        self::assertSame(RefusalKind::NotConfigured, $report->refusal->kind);
    }

    /* ── Helpers ────────────────────────────────────────────────────── */

    /**
     * One indexed chunk of the fixture's own lesson, and the key that cites it.
     */
    private function seedGroundingChunk(): string
    {
        $key = hash('sha256', 'lesson|' . $this->fixture->lessonId . '|0|fixture');

        $this->fixture->env->database->insert('ai_grounding_chunks', [
            'subject_type' => 'lesson',
            'subject_id' => $this->fixture->lessonId,
            'course_id' => $this->fixture->courseId,
            'ordinal' => 0,
            'content_hash' => hash('sha256', 'fixture'),
            'chunk_key' => $key,
            'level' => 'detail',
            'token_count' => 6,
            'text' => 'Steep the leaves for three minutes.',
            'trust' => 'untrusted',
            'indexed_at' => '2026-01-15 10:00:00',
            'created_at' => '2026-01-15 10:00:00',
            'updated_at' => '2026-01-15 10:00:00',
        ]);

        return $key;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function startRun(Principal $principal, array $body, string $key): ApiResponse
    {
        return $this->fixture->call('POST', '/ai/runs', $principal, $body, [], $key);
    }

    private function pipelineRequest(string $taskKey): AiRunRequest
    {
        return AiRunRequest::create(
            $this->fixture->teacher(),
            $taskKey,
            AiTaskRequest::create('course', $this->fixture->courseId),
            null,
            'course',
            $this->fixture->courseId,
            $this->fixture->courseId,
            null,
            ManagementFixture::LEARNER_ID,
        );
    }

    /**
     * The four levels, turned on the way an administrator turns them on.
     *
     * @param list<Principal> $accepting
     * @param list<string>    $disabled  placements left off
     */
    private function enableAi(array $accepting, array $disabled = []): void
    {
        $placements = [];

        foreach ($this->fixture->kernel->registries->aiPlacements->keys() as $key) {
            $placements[$key] = !\in_array($key, $disabled, true);
        }

        $this->fixture->call('PUT', '/manage/ai/enablement', $this->fixture->manager(), [
            'site_enabled' => true,
            'placements' => $placements,
        ], [], 'closure-enable');

        $policy = $this->fixture->call('POST', '/manage/ai/policies', $this->fixture->manager(), [
            'title' => 'How this site uses AI',
            'body' => [
                'based_on_external_models' => 'a',
                'accuracy_varies_check_outputs' => 'b',
                'handled_under_provider_policy' => 'c',
                'interactions_may_be_recorded' => 'd',
                'enabled_tasks' => 'e',
                'route_and_model_family' => 'f',
                'fields_sent_and_never_sent' => 'g',
                'retention_and_where_to_ask' => 'h',
            ],
            'retention_days' => 400,
        ], [], 'closure-policy');

        $version = (int) ($policy->data['version'] ?? 1);
        $this->fixture->call('PATCH', '/manage/ai/policies/' . $version, $this->fixture->manager(), [
            'state' => 'active',
        ], [], 'closure-activate');

        foreach ($accepting as $principal) {
            $this->fixture->call('PUT', '/ai/policy/acceptance', $principal, [
                'version' => $version,
            ], [], 'closure-accept-' . $principal->userId);
        }
    }

    private function addRoute(string $taskKey): void
    {
        $created = $this->fixture->call('POST', '/manage/ai/routes', $this->fixture->manager(), [
            'task_key' => $taskKey,
            'bridge_key' => 'null',
            'max_cost_per_run' => 1000,
            'scope_type' => 'site',
        ], [], 'closure-route-' . $taskKey);

        $this->fixture->call(
            'PATCH',
            '/manage/ai/routes/' . (int) ($created->data['id'] ?? 0),
            $this->fixture->manager(),
            ['enabled' => true],
            [],
            'closure-route-on-' . $taskKey,
        );
    }

    /**
     * The same four levels written straight into their rows, for the cases
     * that drive the pipeline rather than the routes.
     */
    private function enableAiRows(string $taskKey): void
    {
        $now = '2026-01-15 10:00:00';
        $database = $this->fixture->env->database;

        $database->insert('provider_settings', [
            'registry_key' => 'ai',
            'provider_key' => 'enablement',
            'scope_type' => 'site',
            'scope_id' => 0,
            'params' => json_encode(['enabled' => true], JSON_THROW_ON_ERROR),
            'params_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->insert('ai_policies', [
            'version' => 1,
            'title' => 'How this site uses AI',
            'body' => 'The eight parts, written by an administrator.',
            'body_hash' => hash('sha256', 'policy-v1'),
            'effective_from' => '2026-01-01 00:00:00',
            'state' => 'active',
            'created_by' => $this->fixture->manager()->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->insert('ai_policy_acceptances', [
            'user_id' => $this->fixture->teacher()->userId,
            'policy_version' => 1,
            'scope_type' => 'site',
            'scope_id' => 0,
            'accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->insert('ai_routes', [
            'task_key' => $taskKey,
            'bridge_key' => 'null',
            'scope_type' => 'site',
            'scope_id' => 0,
            'enabled' => 1,
            'position' => 0,
            'max_cost_per_run' => 1000,
            'params_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Three learners, one answer each, two of them equivalent.
     */
    private function seedAnswers(): void
    {
        $database = $this->fixture->env->database;
        $texts = ['oolong is semi oxidised', 'Oolong is semi oxidised.', 'green tea is unoxidised'];
        $attempt = 0;

        foreach ([ManagementFixture::LEARNER_ID, ...self::OTHER_LEARNERS] as $learner) {
            ++$attempt;

            $database->insert('attempts', [
                'quiz_id' => $this->fixture->quizId,
                'enrolment_id' => 1,
                'user_id' => $learner,
                'attempt_number' => 1,
                'status' => 'submitted',
                'started_at' => '2026-01-14 10:00:00',
                'submitted_at' => '2026-01-14 10:10:00',
                'created_at' => '2026-01-14 10:00:00',
                'updated_at' => '2026-01-14 10:00:00',
            ]);
            $database->insert('attempt_answers', [
                'attempt_id' => $attempt,
                'question_id' => $this->fixture->questionId,
                'answer' => json_encode($texts[$attempt - 1], JSON_THROW_ON_ERROR),
                'answered_at' => '2026-01-14 10:05:00',
                'created_at' => '2026-01-14 10:05:00',
                'updated_at' => '2026-01-14 10:05:00',
            ]);
        }
    }

    private function rows(string $table): int
    {
        return $this->fixture->env->database->count(Query::from($table));
    }
}
