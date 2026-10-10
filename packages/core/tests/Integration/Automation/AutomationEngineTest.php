<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Automation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Automation\AutomationLoopGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRuleState;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepDefinition;
use Shondalai\VidyaLMS\Core\Domain\Automation\CascadeChain;
use Shondalai\VidyaLMS\Core\Domain\Automation\ReentryMode;
use Shondalai\VidyaLMS\Core\Domain\Automation\SuppressionReason;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\Automation\GateWithoutApproval;
use Shondalai\VidyaLMS\Core\Tests\Support\Automation\NoRehearsalAction;
use Shondalai\VidyaLMS\Core\Tests\Support\Automation\PretendProAction;

/**
 * The free automation engine, end to end on the harness.
 *
 * Every case here is one of the conformance cases the design names, and each
 * exists because the failure it describes was constructible before the code
 * that stops it: a preview that suppresses the thing it previewed, a fan-out of
 * four hundred that executes one, a two-rule cycle nobody can see, a step
 * killed between its claim and its effect.
 */
final class AutomationEngineTest extends TestCase
{
    // The registered enrolment trigger. vidyalms.trigger.* is what label()
    // answers, and the validator now refuses a trigger key no provider
    // resolves on every save path rather than only on the wire.
    private const TRIGGER = 'vidyalms.mail.enrolled';

    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-05-01 09:00:00');
    }

    // ── The nine free actions and the two policy registries ─────────────

    public function testTheNineFreeActionsAreRegisteredAndReachable(): void
    {
        $keys = $this->fixture->kernel->registries->automationActions->keys();
        sort($keys);

        self::assertSame([
            'add_to_cohort',
            'clear_tag',
            'create_review_task',
            'emit_event',
            'enrol',
            'notify',
            'remove_from_cohort',
            'set_tag',
            'unenrol',
        ], $keys);
    }

    public function testTheThreeLaddersAndFourDispositionsAreRegisteredProviders(): void
    {
        $retries = $this->fixture->kernel->registries->retryPolicies->keys();
        $failures = $this->fixture->kernel->registries->failurePolicies->keys();
        sort($retries);
        sort($failures);

        self::assertSame(['external_default', 'gentle', 'none'], $retries);
        self::assertSame(['continue', 'error_branch', 'halt', 'park'], $failures);

        // The published Svix ladder, and the reason the automation outcome
        // window has to outlive it.
        $plan = $this->fixture->kernel->registries->retryPolicies->get('external_default')->plan();
        self::assertSame(8, $plan->maxAttempts);
        self::assertSame([5, 300, 1800, 7200, 18000, 36000, 36000], $plan->ladderSeconds);
        self::assertGreaterThan(97200, $plan->totalLadderSeconds());
    }

    /**
     * The n8n bug, refused at save, and refused by reading two registries
     * rather than two hardcoded lists.
     */
    public function testAnInertRetryPlusContinuePairingIsRefusedAtSave(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/inert_retry_pairing/');

        $this->save($this->rule('inert', [
            $this->step(0, 'set_tag', ['tag' => 'x'], 'external_default', 'continue'),
        ]));
    }

    public function testTheSamePairingIsAcceptedWhenTheLadderNeverRetries(): void
    {
        $rule = $this->save($this->rule('honest', [
            $this->step(0, 'set_tag', ['tag' => 'x'], 'none', 'continue'),
        ]));

        self::assertNotNull($rule->id);
    }

    /**
     * Any action that could publish, mark, gate or issue is refused at save
     * unless it declares RequiresApprovalBeforeEffect. The refusal reads the
     * action's own declaration, so a third-party action that gates access is
     * caught by what it declares rather than by a core file knowing its key.
     */
    public function testAnActionThatCouldGateIsRefusedWithoutTheApprovalDeclaration(): void
    {
        $registries = $this->fixture->kernel->registries;
        $registries->automationActions->register(new GateWithoutApproval());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/action_must_require_approval/');

        $this->save($this->rule('gate', [$this->step(0, GateWithoutApproval::KEY, [])]));
    }

    public function testAnActionWithNoDryRunSupportMayNotBeAuthored(): void
    {
        $this->fixture->kernel->registries->automationActions->register(new NoRehearsalAction());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/must_support_dry_run/');

        $this->save($this->rule('no-rehearsal', [$this->step(0, NoRehearsalAction::KEY, [])]));
    }

    // ── Versioning, ordering and the re-entry fields ────────────────────

    public function testTheFieldsThatCannotBeRetrofittedArePresentFromVersionOne(): void
    {
        $rule = $this->save($this->rule('shape', [$this->step(0, 'set_tag', ['tag' => 'alpha'])]));

        self::assertSame(ReentryMode::Never, $rule->reentry);
        self::assertSame(300, $rule->cooldownSeconds);
        self::assertSame(100, $rule->runOrder);
        self::assertNull($rule->timesPerSubject);
        self::assertNull($rule->totalCompletions);
        self::assertSame(1, $rule->version);
        self::assertSame(AutomationRuleState::Disabled, $rule->state);
    }

    public function testActivatingAVersionRetiresEveryOtherOneAndAnInFlightRunKeepsItsOwn(): void
    {
        $rule = $this->armed('versioned', [$this->step(0, 'set_tag', ['tag' => 'v1'])]);
        $runId = $this->open($rule, 'occ-1');
        self::assertNotNull($runId);

        // Author version two while version one's run is still pending fan-out.
        $repository = $this->fixture->kernel->automationRules;
        $version2 = new AutomationRule(
            $rule->id,
            $rule->ruleKey,
            $rule->title,
            $rule->triggerKey,
            null,
            [$this->step(0, 'set_tag', ['tag' => 'v2'])],
            $rule->reentry,
            null,
            null,
            $rule->cooldownSeconds,
            $rule->runOrder,
            2,
        );
        $repository->save($version2, ManagementFixture::MANAGER_ID);
        $repository->activateVersion($rule->id ?? 0, 2);
        // A version written straight through the repository carries no arming,
        // and the fan-out now refuses a step whose arming digest no longer
        // matches, so the person arms the new version exactly as they would
        // through the route. The run in flight still keeps version one.
        $this->fixture->kernel->manageAutomationRules->arm($this->fixture->manager(), $rule->id ?? 0);

        $run = $this->fixture->kernel->automationRuns->find($runId);
        self::assertNotNull($run);
        self::assertSame(1, $run->ruleVersion, 'an in-flight run must keep the version it started on');

        $this->fixture->kernel->automationFanout->expand($run);
        $steps = $this->fixture->kernel->automationQueue->forRun($runId);
        self::assertCount(1, $steps);
        self::assertSame(1, $steps[0]->ruleVersion);
        self::assertSame('v1', $steps[0]->params['tag'] ?? null, 'the run executed against version two');
    }

    public function testRunOrderIsExplicitMetadataWithCreationTimeAsTheTieBreak(): void
    {
        $this->armed('second', [$this->step(0, 'set_tag', ['tag' => 'b'])], 200);
        $this->armed('first', [$this->step(0, 'set_tag', ['tag' => 'a'])], 50);
        $this->armed('third', [$this->step(0, 'set_tag', ['tag' => 'c'])], 200);

        $ordered = array_map(
            static fn (AutomationRule $rule): string => $rule->ruleKey,
            $this->fixture->kernel->automationRules->enabledForTrigger(self::TRIGGER),
        );

        self::assertSame(['first', 'second', 'third'], $ordered);
    }

    // ── The three loop protections, one test each ───────────────────────

    /**
     * Mechanism one: a rule already on the cascade chain is refused, not only
     * the same rule. The two-rule cycle is invisible to a same-rule filter.
     */
    public function testARuleAlreadyOnTheCascadeChainIsRefused(): void
    {
        $rule = $this->armed('cycle-a', [$this->step(0, 'set_tag', ['tag' => 'a'])]);
        $chain = CascadeChain::of([$rule->id ?? 0]);

        $refusal = $this->fixture->kernel->automationLoops->refusalFor($rule, 'user', 50, 1, $chain);

        self::assertSame(SuppressionReason::CASCADE_REPEAT, $refusal);
    }

    /** Mechanism two: the depth counter against a hard site ceiling. */
    public function testTheCascadeDepthCeilingRefusesADeepRun(): void
    {
        $rule = $this->armed('deep', [$this->step(0, 'set_tag', ['tag' => 'd'])]);
        $ceiling = $this->fixture->kernel->automationLoops->ceiling();

        self::assertSame(AutomationLoopGuard::DEFAULT_CEILING, $ceiling);
        self::assertSame(
            SuppressionReason::CASCADE_CEILING,
            $this->fixture->kernel->automationLoops->refusalFor($rule, 'user', 50, $ceiling, CascadeChain::empty()),
        );
        self::assertNull(
            $this->fixture->kernel->automationLoops->refusalFor($rule, 'user', 50, 0, CascadeChain::empty()),
        );
    }

    /**
     * Mechanism three: the per-rule per-subject cooldown, non-zero by default.
     *
     * The rule re-enters on a new occurrence, so the cooldown is the only
     * thing under test here: with the default `never` the re-entry refusal
     * answers first and this case would be testing that instead.
     */
    public function testTheSubjectCooldownRefusesASecondRunInsideTheWindow(): void
    {
        $rule = $this->armedRepeating('cooldown', [$this->step(0, 'set_tag', ['tag' => 'c'])]);

        self::assertNotNull($this->open($rule, 'occ-1'));
        self::assertSame(
            SuppressionReason::COOLDOWN,
            $this->fixture->kernel->automationLoops->refusalFor($rule, 'user', 50, 0, CascadeChain::empty()),
        );

        $this->fixture->env->clock->set('2026-05-01 09:06:00');
        self::assertNull(
            $this->fixture->kernel->automationLoops->refusalFor($rule, 'user', 50, 0, CascadeChain::empty()),
        );
    }

    /**
     * And the save-time half of mechanism three, which is what stops the two
     * protections that are on by default being defeated by the one that was
     * not.
     */
    public function testARuleThatEmitsEventsIsRefusedAtSaveWithAZeroCooldown(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/emit_event_requires_cooldown/');

        $this->save($this->rule('emitter', [$this->step(0, 'emit_event', ['event_key' => 'x'])], 100, 0));
    }

    /**
     * The whole cycle, run for real: rule A emits B's trigger and rule B emits
     * A's. Both free, both at a non-zero cooldown, and it terminates.
     */
    public function testATwoRuleCycleTerminatesAtTheSecondHop(): void
    {
        $ruleA = $this->armed('cycle-one', [$this->step(0, 'emit_event', ['event_key' => 'automation.event.beta'])]);
        $ruleB = $this->armedOn(
            'cycle-two',
            // The reserved namespace an automation-emitted key lives in: a
            // rule may sit on a registered trigger or on a key another rule
            // emits, and the prefix is what tells the second from a typo.
            'automation.event.beta',
            [$this->step(0, 'emit_event', ['event_key' => self::TRIGGER])],
        );

        $runId = $this->open($ruleA, 'cycle');
        self::assertNotNull($runId);

        // Three turns of fan-out and drain, which is more than the cycle needs
        // to close if it is going to close at all.
        for ($turn = 0; $turn < 3; ++$turn) {
            $this->fixture->kernel->automationFanout->run(50);
            $this->fixture->kernel->automationStep->drain(50);
        }

        $runs = $this->fixture->env->database->count(Query::from('automation_runs'));
        self::assertLessThanOrEqual(
            4,
            $runs,
            'the cycle did not terminate: rule A emits B and B emits A, which is the case a same-rule filter misses',
        );
        self::assertGreaterThan(0, $ruleB->id ?? 0);
    }

    // ── Idempotency, the outbox and the audit ───────────────────────────

    /**
     * The conformance case the design names: one step run twice produces
     * exactly one notification_queue row.
     */
    public function testOneStepRunTwiceProducesExactlyOneNotificationRow(): void
    {
        $rule = $this->armed('notify-once', [$this->step(0, 'notify', [
            'template_key' => 'vidyalms.mail.enrolled',
            'category_key' => 'course_progress',
        ])]);

        $runId = $this->open($rule, 'occ-notify');
        self::assertNotNull($runId);

        $this->fixture->kernel->automationFanout->run(50);
        $step = $this->fixture->kernel->automationQueue->forRun($runId)[0];

        $this->fixture->kernel->automationStep->execute($step);
        $this->fixture->kernel->automationStep->execute($step);

        // Narrowed to the automation's own occurrence key. The fixture's own
        // enrolment mail is on this trigger too, and counting the trigger
        // alone would count it: the row this case is about is the one the step
        // wrote, and there must be exactly one of it however often the step
        // runs.
        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('notification_queue')
                ->where('trigger_key', '=', self::TRIGGER)
                ->where('occurrence_key', '=', 'occ-notify'),
        ));
    }

    /** One step over two subjects produces two distinct idempotency claims. */
    public function testOneStepOverTwoSubjectsProducesTwoDistinctClaims(): void
    {
        $rule = $this->armed('two-subjects', [$this->step(0, 'set_tag', ['tag' => 'both'])]);

        foreach ([50, 10] as $subject) {
            self::assertNotNull($this->open($rule, 'occ-shared', $subject));
        }

        $this->fixture->kernel->automationFanout->run(50);
        $this->fixture->kernel->automationStep->drain(50);

        $claims = $this->fixture->env->database->count(
            Query::from('idempotency_keys')->where('scope', '=', AutomationActionContext::IDEMPOTENCY_SCOPE),
        );

        self::assertSame(2, $claims, 'the subject id is in the canonical digest, so two subjects are two claims');
    }

    /** A retried step produces one claim and one effect. */
    public function testARetriedStepProducesOneClaimAndOneEffect(): void
    {
        $rule = $this->armed('retry-once', [$this->step(0, 'set_tag', ['tag' => 'retry'])]);
        $runId = $this->open($rule, 'occ-retry');
        self::assertNotNull($runId);

        $this->fixture->kernel->automationFanout->run(50);
        $step = $this->fixture->kernel->automationQueue->forRun($runId)[0];

        $this->fixture->kernel->automationStep->execute($step);
        $this->fixture->kernel->automationStep->execute($step);

        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('idempotency_keys')->where('scope', '=', AutomationActionContext::IDEMPOTENCY_SCOPE),
        ));
        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('learner_tags')->where('tag', '=', 'retry'),
        ));
    }

    /**
     * A step killed between the claim and the effect produces exactly one
     * effect after its lease expires.
     *
     * The kill is simulated the way the hosts do it: the row is left claimed
     * with a lease and nothing completes it, which is what a PHP timeout, a
     * WP-Cron death on page unload and Joomla's lazy scheduler all leave
     * behind.
     */
    public function testAStepKilledBetweenClaimAndEffectRunsOnceAfterTheLeaseExpires(): void
    {
        $rule = $this->armed('killed', [$this->step(0, 'set_tag', ['tag' => 'killed'])]);
        $runId = $this->open($rule, 'occ-killed');
        self::assertNotNull($runId);

        $this->fixture->kernel->automationFanout->run(50);
        $step = $this->fixture->kernel->automationQueue->forRun($runId)[0];

        // The claim, and then the process dies.
        self::assertTrue($this->fixture->kernel->automationQueue->claim($step->id ?? 0, 'dead-runner'));
        $this->fixture->kernel->idempotencyStore->claimOrReplay(
            AutomationActionContext::IDEMPOTENCY_SCOPE,
            $step->idemKey,
        );

        self::assertSame(0, $this->fixture->env->database->count(Query::from('learner_tags')));

        // The lease horizon passes. Both the queue row and the ledger claim
        // are released by the same window, which is why the automation scope's
        // claim TTL is the lease horizon rather than a wall clock.
        $this->fixture->env->clock->set('2026-05-01 09:10:00');
        $this->fixture->kernel->automationStep->drain(50);

        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('learner_tags')->where('tag', '=', 'killed'),
        ));
    }

    /** The audit records the identity the step ran under, per action. */
    public function testTheActionLogRecordsTheIdentityTheStepRanUnder(): void
    {
        $rule = $this->armed('audited', [$this->step(0, 'set_tag', ['tag' => 'audited'])]);
        $runId = $this->open($rule, 'occ-audit');
        self::assertNotNull($runId);

        $this->fixture->kernel->automationFanout->run(50);
        $this->fixture->kernel->automationStep->drain(50);

        $entries = $this->fixture->kernel->automationActionLog->forRun($runId);
        self::assertCount(1, $entries);
        self::assertSame(ManagementFixture::MANAGER_ID, $entries[0]->actorUserId);
        self::assertSame('set_tag', $entries[0]->actionKey);
        self::assertTrue($entries[0]->success);
        self::assertSame(50, $entries[0]->subjectId);
        self::assertNotSame('', $entries[0]->idemKey);
    }

    // ── Dry run ─────────────────────────────────────────────────────────

    /**
     * Previewing a rule for a subject and then firing it executes the real
     * run. Without dry_run in the three keys, the preview would occupy the
     * re-entry guard and suppress the subject for good.
     */
    public function testPreviewingARuleAndThenFiringItExecutesTheRealRun(): void
    {
        $rule = $this->armed('previewed', [$this->step(0, 'set_tag', ['tag' => 'previewed'])]);

        $report = $this->fixture->kernel->previewAutomationRule->execute(
            $this->fixture->manager(),
            $rule->id ?? 0,
            [50],
        );

        self::assertSame(1, $report['subject_count']);
        self::assertSame(0, $this->fixture->env->database->count(Query::from('learner_tags')));

        $runId = $this->open($rule, 'occ-real');
        self::assertNotNull($runId, 'the preview occupied the re-entry guard the real run needed');

        $this->fixture->kernel->automationFanout->run(50);
        $this->fixture->kernel->automationStep->drain(50);

        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('learner_tags')->where('tag', '=', 'previewed'),
        ));
    }

    /** dry_run is in all three keys: the run unique, the queue digest and the step claim. */
    public function testDryRunIsInAllThreeKeys(): void
    {
        $real = AutomationActionContext::digest(7, 1, 3, 0, 'set_tag', 'user', 50, 'k', false);
        $dry = AutomationActionContext::digest(7, 1, 3, 0, 'set_tag', 'user', 50, 'k', true);

        self::assertNotSame($real, $dry, 'the step claim and the queue digest');

        $rule = $this->armed('three-keys', [$this->step(0, 'set_tag', ['tag' => 'k'])]);

        $this->fixture->kernel->previewAutomationRule->execute($this->fixture->manager(), $rule->id ?? 0, [50]);
        $runId = $this->open($rule, 'occ-three');

        self::assertNotNull($runId, 'the run unique carries dry_run');
        self::assertSame(2, $this->fixture->env->database->count(
            Query::from('automation_runs')->where('rule_id', '=', $rule->id ?? 0),
        ));
        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('automation_runs')->where('rule_id', '=', $rule->id ?? 0)->where('dry_run', '=', 1),
        ));
    }

    public function testAPreviewNeverCountsAgainstTheSubjectCaps(): void
    {
        $rule = $this->armed('capped', [$this->step(0, 'set_tag', ['tag' => 'capped'])], 100, 300, 1);

        $this->fixture->kernel->previewAutomationRule->execute($this->fixture->manager(), $rule->id ?? 0, [50]);

        self::assertNull(
            $this->fixture->kernel->automationLoops->refusalFor($rule, 'user', 50, 0, CascadeChain::empty()),
            'a preview occupied the times_per_subject cap',
        );
    }

    // ── Stored tests in the availability grammar ────────────────────────

    public function testAStoredTestRunsInTheAvailabilityGrammarAndScoresItself(): void
    {
        $rule = $this->armed('tested', [$this->step(0, 'set_tag', ['tag' => 'tested'])]);

        $test = $this->fixture->kernel->manageAutomationRules->saveTest(
            $this->fixture->manager(),
            new \Shondalai\VidyaLMS\Core\Domain\Automation\StoredRuleTest(
                null,
                $rule->id ?? 0,
                1,
                'one learner is tagged',
                ['subjects' => [50]],
                [],
                ['engine' => [
                    ['kind' => 'step_executed', 'step_index' => 0],
                    ['kind' => 'subject_count', 'count' => 1],
                ]],
                ManagementFixture::MANAGER_ID,
            ),
        );

        $result = $this->fixture->kernel->runStoredRuleTest->execute($this->fixture->manager(), $test->id ?? 0);

        self::assertSame('passed', $result['result'], implode('; ', $result['failures']));

        $stored = $this->fixture->kernel->automationRuleTests->find($test->id ?? 0);
        self::assertNotNull($stored);
        self::assertSame('passed', $stored->lastResult);
        self::assertNotNull($stored->lastRunAt);
    }

    public function testAStoredTestRefusesAnAssertionOutsideTheThreeTheGrammarPublishes(): void
    {
        $rule = $this->armed('bad-assert', [$this->step(0, 'set_tag', ['tag' => 'x'])]);

        $test = $this->fixture->kernel->manageAutomationRules->saveTest(
            $this->fixture->manager(),
            new \Shondalai\VidyaLMS\Core\Domain\Automation\StoredRuleTest(
                null,
                $rule->id ?? 0,
                1,
                'invented comparison',
                ['subjects' => [50]],
                [],
                ['engine' => [['kind' => 'value_equals', 'step_index' => 0]]],
                ManagementFixture::MANAGER_ID,
            ),
        );

        $result = $this->fixture->kernel->runStoredRuleTest->execute($this->fixture->manager(), $test->id ?? 0);

        self::assertSame('failed', $result['result']);
        self::assertContains('automation.unknown_engine_assertion:value_equals', $result['failures']);
    }

    // ── The registry miss, and uninstalling Pro ─────────────────────────

    /**
     * Uninstalling Pro moves every rule carrying a Pro action to degraded and
     * leaves rules using only free actions running.
     *
     * Simulated the way the harness can: a rule is authored while a Pro-shaped
     * action is registered, and then a kernel booted without it reads the same
     * rows. The registry miss is a rule-level condition and never a step
     * failure, which is the whole reason the third state exists.
     */
    public function testUninstallingProDegradesOnlyTheRulesThatNameAProAction(): void
    {
        $registries = $this->fixture->kernel->registries;
        $registries->automationActions->register(new PretendProAction());

        $paid = $this->armed('paid', [
            $this->step(0, 'set_tag', ['tag' => 'free-half']),
            $this->step(1, PretendProAction::KEY, []),
        ]);
        $free = $this->armed('free-only', [$this->step(0, 'set_tag', ['tag' => 'free'])]);

        // The paid package is gone: a second kernel over the same database,
        // with no Pro provider registered.
        $withoutPro = new \Shondalai\VidyaLMS\Core\Boot\CoreKernel($this->fixture->env->ports, [
            'verification_base_url' => 'https://example.test/verify',
        ]);

        $paidRun = $withoutPro->openAutomationRuns->forRule(
            $withoutPro->automationRules->find($paid->id ?? 0) ?? $paid,
            self::TRIGGER,
            $this->occurrence('occ-paid'),
        );
        self::assertNotNull($paidRun);

        $run = $withoutPro->automationRuns->find($paidRun);
        self::assertNotNull($run);
        self::assertSame(0, $withoutPro->automationFanout->expand($run), 'a degraded rule must not fan out');

        $reloaded = $withoutPro->automationRules->find($paid->id ?? 0);
        self::assertNotNull($reloaded);
        self::assertSame(AutomationRuleState::Degraded, $reloaded->state);
        self::assertSame(
            ['action:' . PretendProAction::KEY],
            $withoutPro->automationFanout->missingKeys($reloaded),
        );

        // And the free rule keeps running.
        $freeRun = $withoutPro->openAutomationRuns->forRule(
            $withoutPro->automationRules->find($free->id ?? 0) ?? $free,
            self::TRIGGER,
            $this->occurrence('occ-free'),
        );
        self::assertNotNull($freeRun);
        $freeRunRow = $withoutPro->automationRuns->find($freeRun);
        self::assertNotNull($freeRunRow);
        self::assertSame(1, $withoutPro->automationFanout->expand($freeRunRow));

        $stillFree = $withoutPro->automationRules->find($free->id ?? 0);
        self::assertNotNull($stillFree);
        self::assertSame(AutomationRuleState::Enabled, $stillFree->state);
    }

    // ── The three task types ────────────────────────────────────────────

    public function testTheThreeAutomationTaskTypesAreRegisteredWithTheirDedupeKeys(): void
    {
        $keys = $this->fixture->kernel->registries->scheduledTasks->keys();

        foreach (
            [
            'vidyalms.automation.sweep',
            'vidyalms.automation.fanout',
            'vidyalms.automation.step',
            ] as $type
        ) {
            self::assertContains($type, $keys);
        }

        self::assertSame(
            'vidyalms.automation.fanout:12:3',
            \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationFanoutTask::dedupeKeyFor(12, 3),
        );

        // The step's dedupe key is its own idempotency digest, so the job, the
        // queue row and the ledger claim all name one effect.
        $digest = AutomationActionContext::digest(1, 1, 1, 0, 'set_tag', 'user', 50, 'k', false);
        self::assertSame(
            'vidyalms.automation.step:' . $digest,
            \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationStepTask::dedupeKeyFor($digest),
        );
    }

    /**
     * Each handler owns its own retry and therefore does not throw, the way
     * RunImportTask does: two backoff regimes over one unit of work compose
     * differently under each host's runner.
     */
    public function testEachAutomationHandlerReturnsASummaryRatherThanThrowing(): void
    {
        foreach (
            [
            'vidyalms.automation.sweep',
            'vidyalms.automation.fanout',
            'vidyalms.automation.step',
            ] as $type
        ) {
            $line = $this->fixture->kernel->runScheduledTask($type, 1, [], $this->fixture->manager());
            self::assertIsString($line, $type . ' did not answer a summary line');
            self::assertNotSame('', $line);
        }
    }

    public function testTheStepChunkIsObservableInTheHandlersOwnSummary(): void
    {
        $line = $this->fixture->kernel->runScheduledTask(
            'vidyalms.automation.step',
            1,
            ['chunk' => 7],
            $this->fixture->manager(),
        );

        self::assertIsString($line);
        self::assertStringContainsString('chunk 7', $line);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param list<AutomationStepDefinition> $steps
     */
    private function rule(
        string $key,
        array $steps,
        int $runOrder = 100,
        int $cooldown = 300,
        ?int $timesPerSubject = null,
        string $trigger = self::TRIGGER,
        ReentryMode $reentry = ReentryMode::Never,
    ): AutomationRule {
        return new AutomationRule(
            null,
            $key,
            ucfirst($key),
            $trigger,
            null,
            $steps,
            $reentry,
            $timesPerSubject,
            null,
            $cooldown,
            $runOrder,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function step(
        int $index,
        string $actionKey,
        array $params,
        string $retry = 'gentle',
        string $failure = 'park',
    ): AutomationStepDefinition {
        return new AutomationStepDefinition($index, $actionKey, $params, 1, null, null, [], $retry, $failure);
    }

    private function save(AutomationRule $rule): AutomationRule
    {
        return $this->fixture->kernel->manageAutomationRules->save($this->fixture->manager(), $rule);
    }

    /**
     * @param list<AutomationStepDefinition> $steps
     */
    private function armed(
        string $key,
        array $steps,
        int $runOrder = 100,
        int $cooldown = 300,
        ?int $timesPerSubject = null,
    ): AutomationRule {
        $rule = $this->save($this->rule($key, $steps, $runOrder, $cooldown, $timesPerSubject));

        return $this->fixture->kernel->manageAutomationRules->arm($this->fixture->manager(), $rule->id ?? 0);
    }

    /**
     * An armed rule a subject may enter again on a new occurrence, for the
     * cases that are about a protection other than re-entry.
     *
     * @param list<AutomationStepDefinition> $steps
     */
    private function armedRepeating(string $key, array $steps, int $cooldown = 300): AutomationRule
    {
        $rule = $this->save($this->rule(
            $key,
            $steps,
            100,
            $cooldown,
            null,
            self::TRIGGER,
            ReentryMode::OnNewOccurrence,
        ));

        return $this->fixture->kernel->manageAutomationRules->arm($this->fixture->manager(), $rule->id ?? 0);
    }

    /**
     * @param list<AutomationStepDefinition> $steps
     */
    private function armedOn(string $key, string $trigger, array $steps): AutomationRule
    {
        $rule = $this->save($this->rule($key, $steps, 100, 300, null, $trigger));

        return $this->fixture->kernel->manageAutomationRules->arm($this->fixture->manager(), $rule->id ?? 0);
    }

    private function open(AutomationRule $rule, string $occurrenceKey, int $subjectId = 50): ?int
    {
        return $this->fixture->kernel->openAutomationRuns->forRule(
            $rule,
            $rule->triggerKey,
            $this->occurrence($occurrenceKey, $subjectId),
        );
    }

    private function occurrence(string $occurrenceKey, int $subjectId = 50): TriggerOccurrence
    {
        return TriggerOccurrence::forSweep(self::TRIGGER, $subjectId, $occurrenceKey, 'user', $subjectId);
    }

    /* ── INT-116: the free-only behaviour of the two cohort actions ──── */

    /**
     * Both cohort actions write `enrolments.cohort_id` and nothing else, on a
     * build that has bought nothing.
     *
     * The pair is registered free while the `cohorts` table is declared only
     * in the Pro schema, so on a free site there is no cohort row behind the
     * id. That is a deviation the provider itself names, and it had never been
     * exercised without Pro attached, because the Joomla free harness runs on
     * a site with Pro installed. Here the kernel is the free build alone: the
     * SQLite harness installs the free schema and no other, so `cohorts` does
     * not exist at all and any write that reached for it would fail rather
     * than pass quietly.
     */
    public function testTheCohortPairWritesTheFreeColumnOnABuildWithNoCohortsTable(): void
    {
        $database = $this->fixture->env->database;

        $statement = $database->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table'");
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $tables = $statement->fetchAll(\PDO::FETCH_COLUMN);

        self::assertNotContains(
            'vidyalms_cohorts',
            $tables,
            'the free schema declares no cohorts table, which is the whole point of this case',
        );

        // The fixture's own enrolment: the action refuses a subject with no
        // enrolment on the named course, so the free code path this exercises
        // is the one a real rule reaches.
        $enrolment = $database->selectOne(
            Query::from('enrolments')
                ->where('user_id', '=', ManagementFixture::LEARNER_ID)
                ->where('course_id', '=', $this->fixture->courseId),
        );

        self::assertIsArray($enrolment, 'the learner is on the fixture course');
        $enrolmentId = (int) $enrolment['id'];
        self::assertNull($enrolment['cohort_id'], 'and carries no cohort before the action runs');

        $registry = $this->fixture->kernel->registries->automationActions;
        $add = $registry->get('add_to_cohort');
        $remove = $registry->get('remove_from_cohort');

        $joined = $add->execute($this->cohortContext('add_to_cohort', [
            'course_id' => $this->fixture->courseId,
            'cohort_id' => 4242,
        ]));

        self::assertSame('executed', $joined->disposition->value);
        self::assertSame(
            4242,
            (int) ($database->selectOne(Query::from('enrolments')->where('id', '=', $enrolmentId))['cohort_id'] ?? 0),
            'add_to_cohort stores the id on the enrolment, which is the free column',
        );

        // The idempotent second pass, so a rule that fires twice is not two
        // writes.
        $again = $add->execute($this->cohortContext('add_to_cohort', [
            'course_id' => $this->fixture->courseId,
            'cohort_id' => 4242,
        ]));

        self::assertSame('suppressed', $again->disposition->value);

        $left = $remove->execute($this->cohortContext('remove_from_cohort', [
            'course_id' => $this->fixture->courseId,
        ]));

        self::assertSame('executed', $left->disposition->value);
        $cleared = $database->selectOne(Query::from('enrolments')->where('id', '=', $enrolmentId));
        self::assertIsArray($cleared);
        self::assertNull(
            $cleared['cohort_id'],
            'remove_from_cohort clears the same column rather than deleting a membership row',
        );

        // A subject with no enrolment on the named course is refused rather
        // than enrolled as a side effect.
        $refused = $add->execute($this->cohortContext('add_to_cohort', [
            'course_id' => $this->fixture->otherCourseId,
            'cohort_id' => 4242,
        ]));

        self::assertSame('failed', $refused->disposition->value);
        self::assertSame('cohort.not_enrolled', $refused->reason);

        $database->update('enrolments', ['cohort_id' => null], ['id' => $enrolmentId]);
    }

    public function testAnUnenrolRuleCanNameTheGrantingRuleAndPreviewOnlyExistingAccess(): void
    {
        $f = $this->fixture;
        $action = $f->kernel->registries->automationActions->get('unenrol');
        self::assertInstanceOf(\Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun::class, $action);
        $context = $this->cohortContext('unenrol', ['course_id' => $f->courseId, 'source_rule_id' => 77]);
        self::assertNull($action->previewFor($context)['would_unenrol']);
        $ref = 'automation:77:user:' . ManagementFixture::LEARNER_ID . ':course:' . $f->courseId;
        $f->kernel->grantEntitlement->execute($f->manager(), ManagementFixture::LEARNER_ID, $f->courseId, 'manual', $ref);
        self::assertSame(ManagementFixture::LEARNER_ID, $action->previewFor($context)['would_unenrol']);
        self::assertSame('executed', $action->execute($context)->disposition->value);
        $grant = $f->env->database->selectOne(Query::from('entitlements')->where('source_ref', '=', $ref));
        self::assertNotNull($grant);
        self::assertSame('revoked', $grant['status']);
        self::assertNull($action->previewFor($context)['would_unenrol']);
    }

    /** @param array<string, mixed> $params */
    private function cohortContext(string $actionKey, array $params): AutomationActionContext
    {
        return AutomationActionContext::forStep(
            1,
            1,
            1,
            0,
            $actionKey,
            'int116-' . $actionKey,
            Principal::user(ManagementFixture::MANAGER_ID),
            $this->fixture->env->clock->now(),
            $this->fixture->env->clock->now()->modify('+1 hour'),
            'user',
            ManagementFixture::LEARNER_ID,
            null,
            false,
            1,
            0,
            [],
            $params,
            ManagementFixture::LEARNER_ID,
        );
    }
}
