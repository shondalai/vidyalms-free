<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Automation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Automation\AutomationRuleValidator;
use Shondalai\VidyaLMS\Core\Application\Automation\ManageAutomationRules;
use Shondalai\VidyaLMS\Core\Application\Automation\RunAutomationStep;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationOccurrence;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepDefinition;
use Shondalai\VidyaLMS\Core\Domain\Automation\CascadeChain;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepState;
use Shondalai\VidyaLMS\Core\Domain\Automation\SuppressionReason;
use Shondalai\VidyaLMS\Core\Domain\Automation\ReentryMode;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Automation\EmitEventAction;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\RelativeAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationFanoutTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationStepTask;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\Automation\AcmeBroadcastAction;
use Shondalai\VidyaLMS\Core\Tests\Support\Automation\AcmeCascadeAction;
use Shondalai\VidyaLMS\Core\Tests\Support\Automation\CountingAction;
use Shondalai\VidyaLMS\Core\Tests\Support\Automation\GateWithApproval;
use Shondalai\VidyaLMS\Core\Tests\Support\Automation\TransactionWitnessDatabase;

/**
 * The R6 review findings, one case each, every one of which fails on the code
 * as it was reviewed.
 *
 * They are here rather than folded into AutomationEngineTest because each is a
 * defect with a reproduction rather than a property of the design, and a
 * reader asking "what did the review find and is it still fixed" is owed one
 * file that answers exactly that.
 */
final class AutomationClosureTest extends TestCase
{
    /** The registered enrolment trigger. Its subject is the course and its recipient is the learner. */
    private const TRIGGER = 'vidyalms.mail.enrolled';

    private const SWEEP = 'vidyalms.sweep.entitlement_expiring';

    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-05-01 09:00:00');
    }

    // ── The recipient is not the subject ────────────────────────────────

    /**
     * A sweep occurrence names a learner and a course, and notify mails the
     * learner.
     *
     * automation_runs had no recipient column, so the recipient was lost at
     * the outbox write and notify read `subjectId ?? 0`: an entitlement
     * expiring for learner 50 on course 1 queued the notice to user 1, who is
     * the site manager, because the course id and the manager id collide.
     */
    public function testTheOccurrenceRecipientReachesNotifyRatherThanTheSubjectId(): void
    {
        $rule = $this->armed('expiry', self::SWEEP, [$this->step(0, 'notify', [
            'template_key' => 'vidyalms.mail.enrolled',
            'category_key' => 'course_progress',
        ])]);

        $runId = $this->fixture->kernel->openAutomationRuns->forRule(
            $rule,
            self::SWEEP,
            TriggerOccurrence::forSweep(self::SWEEP, 50, '2026-05-01T09', 'course', 1),
        );

        self::assertNotNull($runId);

        $run = $this->fixture->kernel->automationRuns->find($runId);
        self::assertNotNull($run);
        self::assertSame('course', $run->subjectType);
        self::assertSame(1, $run->subjectId);
        self::assertSame(50, $run->recipientUserId, 'the recipient is carried, not derived from the subject');
        self::assertSame(50, $run->actsForUserId());

        $this->fixture->kernel->automationFanout->run(50);
        $this->fixture->kernel->automationStep->drain(50);

        $recipients = $this->fixture->env->database->selectAll(
            Query::from('notification_queue')
                ->select(['recipient_user_id'])
                ->whereContains('event_ref', 'sweep:' . self::SWEEP)
                ->orderBy('id'),
        );

        self::assertNotSame([], $recipients, 'the step queued nothing at all');

        foreach ($recipients as $row) {
            self::assertSame(50, (int) $row['recipient_user_id']);
        }
    }

    // ── Re-entry ────────────────────────────────────────────────────────

    /**
     * A rule set to never re-enters nobody, however many occurrence keys the
     * sweep mints.
     *
     * The hourly sweep bucket is the occurrence key, so three turns of the
     * clock were three keys, the unique index saw no collision and the mode
     * was never consulted: three runs and three queued mails for one
     * entitlement expiring once.
     */
    public function testAReentryNeverRuleOpensOneRunAcrossThreeSweepBuckets(): void
    {
        $rule = $this->armed('never-again', self::SWEEP, [$this->step(0, 'set_tag', ['tag' => 'expiring'])]);

        // The clock moves an hour between buckets, so the cooldown has lapsed
        // every time and re-entry is the only thing that can refuse the second
        // and third entries. Without that the cooldown answers first and this
        // case would pass whether or not the mode is read at all.
        foreach (['09' => '2026-05-01T09', '10' => '2026-05-01T10', '11' => '2026-05-01T11'] as $hour => $bucket) {
            $this->fixture->env->clock->set('2026-05-01 ' . $hour . ':05:00');
            $this->fixture->kernel->openAutomationRuns->forRule(
                $rule,
                self::SWEEP,
                TriggerOccurrence::forSweep(self::SWEEP, 50, $bucket, 'user', 50),
            );
        }

        self::assertSame(1, $this->runCount($rule->id ?? 0));

        // And the refusal names itself, so an operator counting suppressions
        // can tell re-entry from a cooldown.
        self::assertSame(
            SuppressionReason::REENTRY_BLOCKED,
            $this->fixture->kernel->automationLoops->refusalFor($rule, 'user', 50, 0, CascadeChain::empty()),
        );
    }

    /** always mints a repeat key, which is the only mode that wants a second run inside one occurrence. */
    public function testReentryAlwaysMintsARepeatKeyRatherThanColliding(): void
    {
        $rule = $this->armed(
            'always-again',
            self::SWEEP,
            [$this->step(0, 'set_tag', ['tag' => 'again'])],
            ReentryMode::Always,
            1,
        );

        foreach ([0, 1, 2] as $minute) {
            $this->fixture->env->clock->set(sprintf('2026-05-01 09:0%d:00', $minute + 1));
            $this->fixture->kernel->openAutomationRuns->forRule(
                $rule,
                self::SWEEP,
                TriggerOccurrence::forSweep(self::SWEEP, 50, '2026-05-01T09', 'user', 50),
            );
        }

        self::assertSame(3, $this->runCount($rule->id ?? 0));
        self::assertSame('occ', AutomationOccurrence::repeatKey('occ', 1));
        self::assertSame('occ#2', AutomationOccurrence::repeatKey('occ', 2));
    }

    // ── Arming and versions ─────────────────────────────────────────────

    /**
     * Editing an armed rule stops it, and says so.
     *
     * The assembler passed active false and omitted armed_at and armed_digest
     * while preserving armed_by, so the stored row carried a half-cleared
     * arming triple, the API answered state enabled with a non-null armed_by,
     * and enabledForTrigger answered nothing.
     */
    public function testEditingAnArmedRuleClearsTheWholeArmingTripleAndSaysDisabled(): void
    {
        $created = $this->fixture->call('POST', '/manage/automation-rules', $this->fixture->manager(), [
            'title' => 'Tag on enrolment',
            'trigger_key' => self::TRIGGER,
            'actions' => [['action_key' => 'set_tag', 'params' => ['tag' => 'welcome']]],
        ], [], 'create-1');
        self::assertSame(201, $created->status);

        $id = (int) ($created->data['id'] ?? 0);
        $armed = $this->fixture->call('POST', '/manage/automation-rules/' . $id . '/arm', $this->fixture->manager(), [
            'expected_version' => 1,
            'acknowledgement' => 'this_rule_may_act_on_people',
        ], [], 'arm-1');
        self::assertSame(200, $armed->status);
        self::assertSame('enabled', $armed->data['state'] ?? null);

        $patched = $this->fixture->call('PATCH', '/manage/automation-rules/' . $id, $this->fixture->manager(), [
            'title' => 'Tag on enrolment, renamed',
            'expected_version' => 1,
        ], [], 'patch-1');

        self::assertSame(200, $patched->status);
        self::assertSame('disabled', $patched->data['state'] ?? null, 'the screen must show what happened');
        self::assertArrayHasKey('armed_by', $patched->data);
        self::assertNull($patched->data['armed_by'], 'a half-cleared arming triple is impossible');
        self::assertNull($patched->data['armed_at']);
        self::assertNull($patched->data['armed_digest']);

        $row = $this->fixture->env->database->selectOne(Query::from('automation_rules')->where('id', '=', $id));
        self::assertNotNull($row);
        self::assertNull($row['armed_at']);
        self::assertNull($row['armed_digest']);
        self::assertSame(2, (int) $row['version'], 'the edit is a new version');
    }

    /**
     * The version bump is the use case's, so an importer gets it too, and the
     * steps of the version an in-flight run is executing are never rewritten.
     */
    public function testANonHttpSaveBumpsTheVersionAndLeavesTheRunningVersionAlone(): void
    {
        $rule = $this->armed('versioned', self::TRIGGER, [$this->step(0, 'set_tag', ['tag' => 'v1'])]);
        $runId = $this->open($rule, 'occ-v1');
        self::assertNotNull($runId);

        $edited = $this->fixture->kernel->manageAutomationRules->save(
            $this->fixture->manager(),
            new AutomationRule(
                $rule->id,
                $rule->ruleKey,
                $rule->title,
                $rule->triggerKey,
                null,
                [$this->step(0, 'set_tag', ['tag' => 'v2'])],
            ),
        );

        self::assertSame(2, $edited->version, 'the use case bumps, not the wire mapper');

        $stillOne = $this->fixture->kernel->automationRules->stepsFor($rule->id ?? 0, 1);
        self::assertCount(1, $stillOne);
        self::assertSame('v1', $stillOne[0]->params['tag'] ?? null, 'version one was rewritten underneath the run');
    }

    /** armingStillHolds is a control: the fan-out refuses a step whose digest moved. */
    public function testTheFanOutRefusesAStepWhoseArmingDigestNoLongerHolds(): void
    {
        $rule = $this->armed('moved', self::TRIGGER, [$this->step(0, 'set_tag', ['tag' => 'moved'])]);
        $runId = $this->open($rule, 'occ-moved');
        self::assertNotNull($runId);

        self::assertTrue(ManageAutomationRules::armingStillHolds(
            $this->fixture->kernel->automationRules->find($rule->id ?? 0) ?? $rule,
        ));

        $this->fixture->kernel->automationRules->disarm($rule->id ?? 0);
        $run = $this->fixture->kernel->automationRuns->find($runId);
        self::assertNotNull($run);

        self::assertSame(0, $this->fixture->kernel->automationFanout->expand($run));
        self::assertSame([], $this->fixture->kernel->automationQueue->forRun($runId));
    }

    // ── Approval ────────────────────────────────────────────────────────

    /**
     * The approval gate reads the action's declaration on every save path, not
     * just the wire one, and the effect does not run before the release.
     */
    public function testAnActionDeclaringApprovalIsGatedEvenWhenSavedThroughTheUseCase(): void
    {
        $action = new GateWithApproval();
        $this->fixture->kernel->registries->automationActions->register($action);

        $rule = $this->armed('gated', self::TRIGGER, [$this->step(0, GateWithApproval::KEY, [])]);
        $stored = $this->fixture->kernel->automationRules->stepsFor($rule->id ?? 0, $rule->version);
        self::assertTrue($stored[0]->requiresApprovalBeforeEffect, 'the column is derived in the use case');

        $runId = $this->open($rule, 'occ-gate');
        self::assertNotNull($runId);
        $this->fixture->kernel->automationFanout->run(50);

        $steps = $this->fixture->kernel->automationQueue->forRun($runId);
        self::assertCount(1, $steps);
        self::assertSame(AutomationStepState::AwaitingApproval, $steps[0]->state);

        foreach (['2026-06-01 09:00:00', '2026-07-01 09:00:00', '2026-08-01 09:00:00'] as $later) {
            $this->fixture->env->clock->set($later);
            $this->fixture->kernel->automationStep->drain(50);
        }

        self::assertSame(0, $action->effects, 'a gate with no release is a step that waits for ever');
    }

    /** And the release exists, names the person, and runs the effect exactly once. */
    public function testAReleasedStepRunsExactlyOnceUnderTheReleasingPerson(): void
    {
        $action = new GateWithApproval();
        $this->fixture->kernel->registries->automationActions->register($action);

        $rule = $this->armed('released', self::TRIGGER, [$this->step(0, GateWithApproval::KEY, [])]);
        $runId = $this->open($rule, 'occ-release');
        self::assertNotNull($runId);
        $this->fixture->kernel->automationFanout->run(50);

        $response = $this->fixture->call(
            'POST',
            sprintf('/manage/automation-rules/%d/runs/%d/actions/0/release', $rule->id ?? 0, $runId),
            $this->fixture->manager(),
            ['note' => 'checked with the course lead'],
            [],
            'release-1',
        );

        self::assertSame(200, $response->status);
        self::assertSame('awaiting_approval', $response->data['released_from'] ?? null);
        self::assertSame('pending', $response->data['state'] ?? null);
        self::assertSame(ManagementFixture::MANAGER_ID, $response->data['released_by'] ?? null);

        $this->fixture->kernel->automationStep->drain(50);
        self::assertSame(1, $action->effects);

        $this->fixture->kernel->automationStep->drain(50);
        self::assertSame(1, $action->effects, 'and a second drain does nothing');

        // A second release of a row that has moved on is refused rather than
        // running the effect twice.
        $second = $this->fixture->call(
            'POST',
            sprintf('/manage/automation-rules/%d/runs/%d/actions/0/release', $rule->id ?? 0, $runId),
            $this->fixture->manager(),
            [],
            [],
            'release-2',
        );
        self::assertSame(422, $second->status);
    }

    /** The refusal turns on the declaration and not on seven English words. */
    public function testAnActionThatDeclaresNothingIsRefusedRatherThanWavedThrough(): void
    {
        $this->fixture->kernel->registries->automationActions->register(new AcmeBroadcastAction(
            $this->fixture->kernel->openAutomationRuns,
        ));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/action_must_declare_effect_class/');

        $this->fixture->kernel->manageAutomationRules->save(
            $this->fixture->manager(),
            $this->rule('undeclared', self::TRIGGER, [$this->step(0, AcmeBroadcastAction::KEY, [])], 300),
        );
    }

    // ── Loop protection ─────────────────────────────────────────────────

    /**
     * A third-party action that opens runs is bounded by the ceiling even
     * though it stamps nothing itself, and a cooldown of zero is refused
     * because it declares EmitsOccurrences rather than because of its key.
     */
    public function testAThirdPartyCascadeIsStampedByTheEngineAndStopsAtTheCeiling(): void
    {
        $this->fixture->kernel->registries->automationActions->register(new AcmeCascadeAction(
            $this->fixture->kernel->openAutomationRuns,
        ));

        $refused = null;

        try {
            $this->fixture->kernel->manageAutomationRules->save(
                $this->fixture->manager(),
                $this->rule('acme-zero', self::TRIGGER, [$this->step(0, AcmeCascadeAction::KEY, [])], 0),
            );
        } catch (ValidationException $failure) {
            $refused = $failure->getMessage();
        }

        self::assertIsString($refused);
        self::assertStringContainsString('emit_event_requires_cooldown', $refused);

        // And the stamping. Two rules that broadcast into each other cannot
        // run away, because the depth and the chain come from the engine's own
        // frame rather than from whatever the action chose to pass.
        $alpha = $this->armed(
            'acme-a',
            self::TRIGGER,
            [$this->step(0, AcmeCascadeAction::KEY, ['event_key' => 'automation.event.beta'])],
        );
        $beta = $this->armed(
            'acme-b',
            'automation.event.beta',
            [$this->step(0, AcmeCascadeAction::KEY, ['event_key' => 'automation.event.alpha'])],
        );

        self::assertNotNull($this->open($alpha, 'occ-cascade'));

        for ($turn = 0; $turn < 12; $turn++) {
            $this->fixture->env->clock->set(sprintf('2026-05-01 %02d:00:00', 10 + $turn));
            $this->fixture->kernel->automationFanout->run(50);
            $this->fixture->kernel->automationStep->drain(50);
        }

        $depths = $this->fixture->env->database->selectAll(
            Query::from('automation_runs')->select(['cascade_depth', 'rule_chain'])->orderBy('id'),
        );

        $ceiling = $this->fixture->kernel->automationLoops->ceiling();
        $total = $this->runCount($alpha->id ?? 0) + $this->runCount($beta->id ?? 0);

        self::assertLessThanOrEqual($ceiling + 1, $total, 'the cascade grew without bound');
        self::assertGreaterThan(
            0,
            \count(array_filter($depths, static fn (array $row): bool => (int) $row['cascade_depth'] > 0)),
            'nothing was stamped, so the ceiling could never be reached',
        );
    }

    // ── Idempotency ─────────────────────────────────────────────────────

    /**
     * A step killed after its effect and before its outcome runs the effect
     * once, not twice.
     *
     * The claim, the effect and the outcome commit together, so a kill rolls
     * all three back and the lease returns a row that has genuinely not acted.
     * Before that, the three were separate commits and the lease reclaim found
     * no claim to stop the retry.
     */
    public function testAKilledStepDoesNotRunItsEffectASecondTime(): void
    {
        // A process killed mid-step cannot be staged inside one PHP process,
        // so the property that stops it is asserted directly: the effect runs
        // inside a transaction, so the claim, the effect and the outcome roll
        // back together and the lease returns a row that has genuinely not
        // acted. Three separate commits could not have that property, which is
        // why the effect ran a second time once the lease expired.
        $witness = new TransactionWitnessDatabase($this->fixture->env->database);
        $action = new CountingAction($witness);
        $this->fixture->kernel->registries->automationActions->register($action);

        $steps = new RunAutomationStep(
            $this->fixture->kernel->automationQueue,
            $this->fixture->kernel->automationRules,
            $this->fixture->kernel->automationRuns,
            $this->fixture->kernel->automationActionLog,
            $this->fixture->kernel->registries->automationActions,
            $this->fixture->kernel->registries->retryPolicies,
            $this->fixture->kernel->registries->failurePolicies,
            $this->fixture->kernel->idempotencyStore,
            $this->fixture->env->clock,
            new \Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard($this->fixture->env->authorization),
            null,
            $witness,
        );

        $rule = $this->armed('counted', self::TRIGGER, [$this->step(0, CountingAction::KEY, [])]);
        $runId = $this->open($rule, 'occ-count');
        self::assertNotNull($runId);
        $this->fixture->kernel->automationFanout->run(50);
        $steps->drain(50);

        self::assertSame(1, $action->effects);
        self::assertGreaterThan(
            0,
            $action->depthAtEffect,
            'the effect ran outside a transaction, so a kill between it and the outcome loses the claim and not the effect',
        );

        // And past the queue lease and the claim TTL, which are the same
        // horizon by design, the effect stays at one.
        $this->fixture->env->clock->set('2026-05-01 10:00:00');
        $steps->drain(50);

        self::assertSame(1, $action->effects, 'the effect ran a second time after the lease expired');
    }

    /** The queue row and the ledger claim carry one key, discriminator included. */
    public function testTheQueueRowCarriesTheKeyTheLedgerIsClaimedUnder(): void
    {
        // An action that declares a discriminator, because an action that
        // declares none cannot show the disagreement: the discriminator used
        // to be applied only in the step machine, after the row existed, so
        // idem_key named one effect and the claim named another.
        $this->fixture->kernel->registries->automationActions->register(new CountingAction());

        $rule = $this->armed('keyed', self::TRIGGER, [$this->step(0, CountingAction::KEY, [])]);
        $runId = $this->open($rule, 'occ-key');
        self::assertNotNull($runId);
        $this->fixture->kernel->automationFanout->run(50);
        $this->fixture->kernel->automationStep->drain(50);

        $steps = $this->fixture->kernel->automationQueue->forRun($runId);
        self::assertCount(1, $steps);
        self::assertStringContainsString(
            ':' . hash('sha256', CountingAction::DISCRIMINATOR),
            $steps[0]->idemKey,
            'the queue row does not carry the discriminated key at all',
        );

        $claims = $this->fixture->env->database->selectAll(
            Query::from('idempotency_keys')
                ->select(['idem_key'])
                ->where('scope', '=', AutomationActionContext::IDEMPOTENCY_SCOPE),
        );

        self::assertCount(1, $claims);
        self::assertSame($steps[0]->idemKey, (string) $claims[0]['idem_key']);
    }

    /** emit_event's occurrence key fits the column that stores it. */
    public function testAnEmittedOccurrenceKeyFitsItsColumn(): void
    {
        $wide = str_repeat('e', 100);
        $bucket = AutomationOccurrence::emittedBucket(999999, 999999, $wide);

        self::assertLessThanOrEqual(AutomationOccurrence::KEY_LIMIT, \strlen($bucket));
        self::assertNotSame(
            $bucket,
            AutomationOccurrence::emittedBucket(999999, 999999, $wide . 'x'),
            'two distinct emissions must not share one occurrence',
        );

        $rule = $this->armed(
            'emitter',
            self::TRIGGER,
            [$this->step(0, EmitEventAction::KEY, ['event_key' => $wide])],
        );
        $runId = $this->open($rule, 'occ-emit');
        self::assertNotNull($runId);
        $this->fixture->kernel->automationFanout->run(50);
        $this->fixture->kernel->automationStep->drain(50);

        $widest = 0;

        foreach (
            $this->fixture->env->database->selectAll(
                Query::from('automation_runs')->select(['occurrence_key']),
            ) as $row
        ) {
            $widest = max($widest, \strlen((string) $row['occurrence_key']));
        }

        self::assertLessThanOrEqual(AutomationOccurrence::KEY_LIMIT, $widest);
    }

    // ── Dry run, scope, deferral ────────────────────────────────────────

    /** A rehearsal moves none of the four counters the rules screen shows. */
    public function testARehearsalMovesNoRuleCounter(): void
    {
        $rule = $this->armed('rehearsed', self::TRIGGER, [$this->step(0, 'set_tag', ['tag' => 'rehearsed'])]);

        $this->fixture->kernel->previewAutomationRule->execute($this->fixture->manager(), $rule->id ?? 0, [50], 'user');
        $this->fixture->kernel->previewAutomationRule->execute($this->fixture->manager(), $rule->id ?? 0, [50], 'user');

        $read = $this->fixture->kernel->automationRules->find($rule->id ?? 0);
        self::assertNotNull($read);
        self::assertSame(0, $read->queuedCount);
        self::assertSame(0, $read->executedCount);
    }

    /** A scope the engine cannot enforce is refused at save rather than ignored. */
    public function testAnUnenforceableScopeIsRefusedAtSave(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/organisation_scope_not_enforced_yet/');

        $rule = $this->rule('scoped', self::TRIGGER, [$this->step(0, 'set_tag', ['tag' => 'scoped'])], 300);
        $this->fixture->kernel->manageAutomationRules->save($this->fixture->manager(), new AutomationRule(
            null,
            $rule->ruleKey,
            $rule->title,
            $rule->triggerKey,
            null,
            $rule->steps,
            organisationId: 5,
        ));
    }

    /** A deferred row is stored as deferred and still comes back when its moment arrives. */
    public function testADeferredRowIsWrittenAsDeferredAndStillDrains(): void
    {
        $action = new \Shondalai\VidyaLMS\Core\Tests\Support\Automation\DeferOnceAction();
        $this->fixture->kernel->registries->automationActions->register($action);

        $rule = $this->armed('deferred', self::TRIGGER, [$this->step(0, $action->key(), [])]);
        $runId = $this->open($rule, 'occ-defer');
        self::assertNotNull($runId);
        $this->fixture->kernel->automationFanout->run(50);
        $this->fixture->kernel->automationStep->drain(50);

        $steps = $this->fixture->kernel->automationQueue->forRun($runId);
        self::assertSame(AutomationStepState::Deferred, $steps[0]->state);

        $this->fixture->env->clock->set('2026-05-01 11:00:00');
        $this->fixture->kernel->automationStep->drain(50);
        self::assertSame(1, $action->effects);
    }

    // ── Anchors ─────────────────────────────────────────────────────────

    /** An anchor no provider answers degrades the rule rather than releasing the step now. */
    public function testAnUnresolvableAnchorDegradesTheRuleRatherThanRunningNow(): void
    {
        $rule = $this->armed('anchored', self::TRIGGER, [new AutomationStepDefinition(
            0,
            'set_tag',
            ['tag' => 'delayed'],
            1,
            null,
            'acme.absent_anchor',
            ['days' => 30],
            'gentle',
            'park',
        )]);

        self::assertContains('anchor:acme.absent_anchor', $this->fixture->kernel->automationFanout->missingKeys($rule));

        $runId = $this->open($rule, 'occ-anchor');
        self::assertNotNull($runId);
        $this->fixture->kernel->automationFanout->run(50);

        self::assertSame([], $this->fixture->kernel->automationQueue->forRun($runId), 'the step was released');

        $row = $this->fixture->env->database->selectOne(
            Query::from('automation_rules')->where('id', '=', $rule->id ?? 0),
        );
        self::assertNotNull($row);
        self::assertSame('degraded', (string) $row['state']);
    }

    /** The relative anchor is what makes "three days after enrolment" expressible at all. */
    public function testTheRelativeAnchorAppliesTheOffsetTheOtherFiveNeverDid(): void
    {
        self::assertTrue($this->fixture->kernel->registries->scheduleAnchors->has(RelativeAnchor::KEY));

        $rule = $this->armed('relative', self::TRIGGER, [new AutomationStepDefinition(
            0,
            'set_tag',
            ['tag' => 'later'],
            1,
            null,
            RelativeAnchor::KEY,
            ['base_anchor_key' => 'absolute', 'at' => '2026-05-01 09:00:00', 'amount' => 3, 'unit' => 'days'],
            'gentle',
            'park',
        )]);

        $runId = $this->open($rule, 'occ-relative');
        self::assertNotNull($runId);
        $this->fixture->kernel->automationFanout->run(50);

        $steps = $this->fixture->kernel->automationQueue->forRun($runId);
        self::assertCount(1, $steps);
        self::assertSame('2026-05-04 09:00:00', $steps[0]->runAt->format('Y-m-d H:i:s'));
    }

    /** Every anchor publishes the payload it reads, so an author guesses nothing. */
    public function testEveryAnchorPublishesItsParamsSchema(): void
    {
        $catalogue = $this->fixture->kernel->automationCatalogue->execute($this->fixture->manager());

        self::assertNotSame([], $catalogue['anchors']);

        foreach ($catalogue['anchors'] as $anchor) {
            self::assertArrayHasKey('params_schema', $anchor);
            self::assertNotSame([], $anchor['params_schema'], (string) $anchor['key']);
        }
    }

    // ── The seams ───────────────────────────────────────────────────────

    /** applicability() answers the shape the seam declares, on every shipped action. */
    public function testEveryShippedActionAnswersTheDeclaredApplicabilityShape(): void
    {
        $registry = $this->fixture->kernel->registries->automationActions;
        self::assertCount(9, $registry->keys());

        foreach ($registry->keys() as $key) {
            $declared = $registry->get($key)->applicability();

            self::assertSame(['subject_types', 'trigger_keys'], array_keys($declared), $key);
            self::assertNotSame([], $declared['subject_types'] ?? [], $key);
        }
    }

    /** And the shape is enforced: a step behind a trigger it excludes is refused. */
    public function testAnActionIsRefusedBehindATriggerItsApplicabilityExcludes(): void
    {
        $this->fixture->kernel->registries->automationActions->register(new AcmeBroadcastAction(
            $this->fixture->kernel->openAutomationRuns,
            ['vidyalms.mail.course_completed'],
        ));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/action_not_applicable_to_trigger/');

        $this->fixture->kernel->manageAutomationRules->save(
            $this->fixture->manager(),
            $this->rule('misapplied', self::TRIGGER, [$this->step(0, AcmeBroadcastAction::KEY, [])], 300),
        );
    }

    /** Every trigger the builder offers publishes the subject types it can yield. */
    public function testTheCatalogueOffersRealSubjectTypesRatherThanAnEmptyList(): void
    {
        $catalogue = $this->fixture->kernel->automationCatalogue->execute($this->fixture->manager());

        self::assertNotSame([], $catalogue['triggers']);

        foreach ($catalogue['triggers'] as $trigger) {
            self::assertNotSame([], $trigger['subject_types'], (string) $trigger['key']);
        }

        foreach ($catalogue['actions'] as $action) {
            self::assertIsString($action['sentence'], (string) $action['key']);
            self::assertIsString($action['effect_class'], (string) $action['key']);
        }
    }

    // ── The trigger vocabulary ──────────────────────────────────────────

    /** An unregistered trigger is refused on every save path, not only the wire one. */
    public function testAnUnrunnableTriggerIsRefusedThroughTheUseCase(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/unknown_trigger/');

        $this->fixture->kernel->manageAutomationRules->save(
            $this->fixture->manager(),
            $this->rule('nowhere', 'no.such.trigger.anywhere', [$this->step(0, 'set_tag', ['tag' => 'x'])], 300),
        );
    }

    /** An emitted key is authored in the reserved namespace and is accepted there. */
    public function testAnEmittedTriggerKeyIsAcceptedInItsReservedNamespace(): void
    {
        $saved = $this->fixture->kernel->manageAutomationRules->save(
            $this->fixture->manager(),
            $this->rule(
                'listener',
                AutomationRuleValidator::EMITTED_TRIGGER_PREFIX . 'beta',
                [$this->step(0, 'set_tag', ['tag' => 'beta'])],
                300,
            ),
        );

        self::assertNotNull($saved->id);
    }

    /** A token the trigger cannot supply is refused rather than rendered as braces. */
    public function testAPlaceholderTheTriggerDoesNotSupplyIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/unknown_placeholder:cohort_name/');

        $this->fixture->kernel->manageAutomationRules->save(
            $this->fixture->manager(),
            $this->rule(
                'tokened',
                self::TRIGGER,
                [$this->step(0, 'set_tag', ['tag' => 'welcome-{{cohort_name}}'])],
                300,
            ),
        );
    }

    // ── The outbox and the jobs ─────────────────────────────────────────

    /** A committed enrolment opens the automation runs its trigger names. */
    public function testAnEventTriggerOpensAnAutomationRun(): void
    {
        $rule = $this->armed('on-enrol', self::TRIGGER, [$this->step(0, 'set_tag', ['tag' => 'enrolled'])]);

        $before = $this->runCount($rule->id ?? 0);
        self::assertSame(0, $before);

        $this->fixture->kernel->enrolLearnerManually->execute(
            $this->fixture->manager(),
            $this->fixture->courseId,
            ManagementFixture::OUTSIDER_ID,
        );

        self::assertSame(1, $this->runCount($rule->id ?? 0), 'an event trigger could never open a run');
    }

    /** And the two per-row jobs the design published are actually queued. */
    public function testTheFanOutAndStepJobsAreQueuedRatherThanWaitingForTheClock(): void
    {
        $rule = $this->armed('woken', self::TRIGGER, [$this->step(0, 'set_tag', ['tag' => 'woken'])]);
        $runId = $this->open($rule, 'occ-woken');
        self::assertNotNull($runId);

        $keys = array_map(
            static fn (object $task): ?string => $task->dedupeKey,
            $this->fixture->env->scheduler->tasks,
        );

        self::assertContains(AutomationFanoutTask::dedupeKeyFor($runId, 0), $keys);

        $this->fixture->kernel->automationFanout->run(50);
        $steps = $this->fixture->kernel->automationQueue->forRun($runId);
        self::assertCount(1, $steps);

        $keys = array_map(
            static fn (object $task): ?string => $task->dedupeKey,
            $this->fixture->env->scheduler->tasks,
        );

        self::assertContains(AutomationStepTask::dedupeKeyFor($steps[0]->idemKey), $keys);
    }

    // ── The stored shapes and the reference rows ────────────────────────

    /** A rule's condition references are written under its own owner type. */
    public function testARulesConditionReferencesAreWrittenUnderTheAutomationOwnerType(): void
    {
        $rule = $this->fixture->kernel->manageAutomationRules->save(
            $this->fixture->manager(),
            new AutomationRule(
                null,
                'referenced',
                'Referenced',
                self::TRIGGER,
                self::completionTree($this->fixture->lessonId),
                [$this->step(0, 'set_tag', ['tag' => 'referenced'])],
            ),
        );

        $rows = $this->fixture->env->database->selectAll(
            Query::from('availability_rule_refs')
                ->where('owner_type', '=', SqlAvailabilityRuleRepository::OWNER_AUTOMATION_RULE)
                ->where('owner_id', '=', $rule->id ?? 0),
        );

        self::assertNotSame([], $rows, 'a course copy cannot see this rule references a lesson');
    }

    /** Both condition documents carry a StoredShape envelope, so their version columns describe something. */
    public function testTheTwoConditionDocumentsCarryTheirStoredShapeEnvelope(): void
    {
        $tree = self::completionTree($this->fixture->lessonId);

        $rule = $this->fixture->kernel->manageAutomationRules->save(
            $this->fixture->manager(),
            new AutomationRule(
                null,
                'enveloped',
                'Enveloped',
                self::TRIGGER,
                $tree,
                [new AutomationStepDefinition(
                    0,
                    'set_tag',
                    ['tag' => 'enveloped'],
                    1,
                    $tree,
                    null,
                    [],
                    'gentle',
                    'park',
                )],
            ),
        );

        $row = $this->fixture->env->database->selectOne(
            Query::from('automation_rules')->where('id', '=', $rule->id ?? 0),
        );
        self::assertNotNull($row);
        self::assertStringContainsString('"_v":1', (string) $row['conditions']);

        $step = $this->fixture->env->database->selectOne(
            Query::from('automation_rule_actions')->where('rule_id', '=', $rule->id ?? 0),
        );
        self::assertNotNull($step);
        self::assertStringContainsString('"_v":1', (string) $step['guard_conditions']);

        // And the tree still reads back, which is what makes the envelope
        // additive rather than a migration.
        $read = $this->fixture->kernel->automationRules->find($rule->id ?? 0);
        self::assertNotNull($read);
        self::assertNotNull($read->conditions);
        self::assertNotNull($read->steps[0]->guardConditions);
    }

    /** The delay payload's version is the anchor's, carried rather than hardcoded. */
    public function testTheDelayPayloadCarriesTheVersionTheAuthorNamed(): void
    {
        $created = $this->fixture->call('POST', '/manage/automation-rules', $this->fixture->manager(), [
            'title' => 'Delayed',
            'trigger_key' => self::TRIGGER,
            'actions' => [[
                'action_key' => 'set_tag',
                'params' => ['tag' => 'delayed'],
                'delay_anchor_key' => 'absolute',
                'delay' => ['at' => '2026-06-01 09:00:00'],
                'delay_version' => 2,
            ]],
        ], [], 'delay-1');

        self::assertSame(201, $created->status);

        $row = $this->fixture->env->database->selectOne(
            Query::from('automation_rule_actions')->where('rule_id', '=', (int) ($created->data['id'] ?? 0)),
        );

        self::assertNotNull($row);
        self::assertSame(2, (int) $row['delay_version']);
        self::assertStringContainsString('"_v":2', (string) $row['delay']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * A tree the shipped codec accepts: `c` for children, and the completion
     * leaf's own target and state payload.
     */
    private static function completionTree(int $lessonId): \Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree
    {
        return \Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec::decode([
            'op' => 'and',
            'c' => [[
                'type' => 'completion',
                'payload' => ['target' => ['kind' => 'lesson', 'id' => $lessonId], 'state' => 'complete'],
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function step(int $index, string $actionKey, array $params): AutomationStepDefinition
    {
        return new AutomationStepDefinition($index, $actionKey, $params, 1, null, null, [], 'gentle', 'park');
    }

    /**
     * @param list<AutomationStepDefinition> $steps
     */
    private function rule(string $key, string $trigger, array $steps, int $cooldown): AutomationRule
    {
        return new AutomationRule(
            null,
            $key,
            ucfirst($key),
            $trigger,
            null,
            $steps,
            ReentryMode::Never,
            null,
            null,
            $cooldown,
        );
    }

    /**
     * @param list<AutomationStepDefinition> $steps
     */
    private function armed(
        string $key,
        string $trigger,
        array $steps,
        ReentryMode $reentry = ReentryMode::Never,
        int $cooldown = 300,
    ): AutomationRule {
        $saved = $this->fixture->kernel->manageAutomationRules->save($this->fixture->manager(), new AutomationRule(
            null,
            $key,
            ucfirst($key),
            $trigger,
            null,
            $steps,
            $reentry,
            null,
            null,
            $cooldown,
        ));

        return $this->fixture->kernel->manageAutomationRules->arm($this->fixture->manager(), $saved->id ?? 0);
    }

    private function open(AutomationRule $rule, string $occurrenceKey, int $subjectId = 50): ?int
    {
        return $this->fixture->kernel->openAutomationRuns->forRule(
            $rule,
            $rule->triggerKey,
            TriggerOccurrence::forSweep($rule->triggerKey, $subjectId, $occurrenceKey, 'user', $subjectId),
        );
    }

    private function runCount(int $ruleId): int
    {
        return $this->fixture->env->database->count(
            Query::from('automation_runs')->where('rule_id', '=', $ruleId)->where('dry_run', '=', 0),
        );
    }
}
