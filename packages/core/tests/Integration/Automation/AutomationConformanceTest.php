<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Automation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepDefinition;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepState;
use Shondalai\VidyaLMS\Core\Domain\Automation\ReentryMode;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\Automation\DeferOnceAction;

/**
 * The automation half of prerequisite 19, as a test class that stays.
 *
 * The design lists these as conformance cases rather than as unit tests
 * because each one is a property of the engine as a whole: a claim written
 * before an effect, a lease that releases a claim, a preview that costs the
 * subject nothing. A property asserted in a docblock is a property nobody
 * checks, so they are here.
 *
 * The three shipped signatures are pinned by reflection for the same reason
 * SeatedRegistriesTest pins the AI task contract: the widening was free
 * exactly once, while every one of the eleven registries answered keys()
 * equal to the empty list, and after this wave a seam interface never changes
 * again.
 */
final class AutomationConformanceTest extends TestCase
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

    // ── The widening was free exactly once ──────────────────────────────

    /**
     * The precondition, asserted on the booted kernel this fixture builds.
     *
     * It used to do `new Registries()`, which default-constructs every
     * registry, so it asserted the empty list against an object that has no
     * other state and could never fail. Booted, it said something real while
     * the AI seams were still being widened: filling one had to trip this
     * rather than silently make a widening unsafe.
     *
     * The AI stage that fills them has now landed, so the tripwire has done
     * its job and is replaced by the rule that outlives it. Every seam on
     * both sides carries free providers, which is what the standards page
     * requires of every extension point in the product, and a seam losing its
     * last one fails here.
     */
    public function testEverySeamOnBothSidesCarriesFreeProvidersAtBoot(): void
    {
        $registries = $this->fixture->kernel->registries;

        foreach (
            [
            'aiRecipes', 'aiPlacements', 'aiSurfaces', 'modelBridges',
            'aiPolicies', 'aiRedactors', 'aiFactProviders', 'aiDraftAcceptors',
            ] as $seam
        ) {
            self::assertNotSame([], $registries->{$seam}->keys(), $seam);
        }

        self::assertCount(9, $registries->automationActions->keys());
        self::assertCount(3, $registries->retryPolicies->keys());
        self::assertCount(4, $registries->failurePolicies->keys());
    }

    public function testTheThreeAutomationSeamsCarryTheirDesignedSignatures(): void
    {
        $execute = new \ReflectionMethod(AutomationActionInterface::class, 'execute');
        self::assertCount(1, $execute->getParameters());
        self::assertSame(AutomationActionContext::class, (string) $execute->getParameters()[0]->getType());
        self::assertSame(ActionOutcome::class, (string) $execute->getReturnType());

        self::assertSame(
            ['label', 'paramsSchema', 'applicability', 'execute'],
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                array_filter(
                    (new \ReflectionClass(AutomationActionInterface::class))->getMethods(),
                    static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName()
                        === AutomationActionInterface::class,
                ),
            ),
            'four methods and no more: everything optional is a capability interface',
        );

        $plan = new \ReflectionMethod(RetryPolicyInterface::class, 'plan');
        self::assertSame(
            'Shondalai\VidyaLMS\Core\Registry\Automation\RetryPlan',
            (string) $plan->getReturnType(),
        );

        $disposition = new \ReflectionMethod(FailurePolicyInterface::class, 'disposition');
        self::assertSame(
            'Shondalai\VidyaLMS\Core\Registry\Automation\FailureDisposition',
            (string) $disposition->getReturnType(),
        );
    }

    /**
     * The nine capability interfaces exist and none of them is a method on the
     * action contract, which is the rule the whole design rests on.
     */
    public function testTheNineActionCapabilitiesAreInterfacesAndNotMethods(): void
    {
        foreach (
            [
            'SupportsDryRun',
            'RequiresApprovalBeforeEffect',
            'ProducesReviewItem',
            'DeclaresCost',
            'SupportsBatch',
            'DeclaresReferences',
            'ValidatesPayload',
            'SupportsPayloadVersions',
            'DeclaresIdempotencyDiscriminator',
            ] as $capability
        ) {
            $name = 'Shondalai\VidyaLMS\Core\Registry\Automation\\' . $capability;
            self::assertTrue(interface_exists($name), $capability . ' is not an interface');
            self::assertFalse(
                method_exists(AutomationActionInterface::class, lcfirst($capability)),
                $capability . ' leaked onto the action contract',
            );
        }
    }

    /**
     * idempotencyKeyFor() is deliberately absent, and its absence is the fix
     * for the silent-loss bug: an action answering a constant claims one
     * ledger row and a fan-out of four hundred records 399 replays as
     * executed.
     */
    public function testAnActionCannotSupplyItsOwnIdempotencyKey(): void
    {
        self::assertFalse(method_exists(AutomationActionInterface::class, 'idempotencyKeyFor'));

        // And the appended discriminator can only ever split a key further.
        $base = AutomationActionContext::digest(1, 1, 1, 0, 'k', 'user', 50, 'occ', false);
        $other = AutomationActionContext::digest(1, 1, 1, 0, 'k', 'user', 51, 'occ', false);

        self::assertNotSame($base, $other, 'the subject is in the canonical digest');
    }

    // ── The seven tables and the two value additions ────────────────────

    public function testTheSevenAutomationTablesAreDeclaredWithTheirKeys(): void
    {
        $schema = SchemaDefinition::load();
        $names = array_map(static fn (object $table): string => $table->name, $schema->tables);

        foreach (
            [
            'automation_rules',
            'automation_rule_versions',
            'automation_rule_actions',
            'automation_runs',
            'automation_queue',
            'automation_action_log',
            'automation_rule_tests',
            ] as $table
        ) {
            self::assertContains($table, $names, $table . ' is not declared');
        }

        $uniques = [];

        foreach ($schema->tables as $table) {
            foreach ($table->indexes as $index) {
                if ($index->unique) {
                    $uniques[$table->name][] = implode(',', $index->columns);
                }
            }
        }

        // The re-entry guard, with dry_run in it.
        self::assertContains(
            'rule_id,occurrence_key,subject_type,subject_id,dry_run',
            $uniques['automation_runs'] ?? [],
        );
        // The queue digest, unique on the digest alone for the 767-byte reason.
        self::assertContains('dedupe_key', $uniques['automation_queue'] ?? []);
        // One version active, and the steps versioned with it.
        self::assertContains('rule_key,version', $uniques['automation_rules'] ?? []);
        self::assertContains('rule_id,rule_version,step_index', $uniques['automation_rule_actions'] ?? []);
        // The audit's idem_key is a lookup and never a constraint.
        self::assertNotContains('idem_key', $uniques['automation_action_log'] ?? []);
    }

    public function testTheTwoValueAdditionsAreRecordedInTheSchemaComments(): void
    {
        $tables = file_get_contents(__DIR__ . '/../../../schema/tables.php');
        self::assertIsString($tables);

        self::assertStringContainsString(
            'automation_rule',
            $tables,
            'availability_rule_refs.owner_type gains a fifth value',
        );
        self::assertStringContainsString(
            'rule | organisation | placement',
            $tables,
            'provider_settings.scope_type gains organisation and placement',
        );
    }

    // ── The conformance cases ───────────────────────────────────────────

    /**
     * A step deferred and then resumed executes exactly once.
     *
     * The claim is released by the deferral rather than left to expire,
     * because a claim left behind is found by the step's own resumption and
     * short-circuited to "already done": the effect would never happen and
     * nobody would be told.
     */
    public function testAStepDeferredAndThenResumedExecutesExactlyOnce(): void
    {
        $action = new DeferOnceAction();
        $this->fixture->kernel->registries->automationActions->register($action);

        $rule = $this->armed('deferring', [$this->step(0, DeferOnceAction::KEY, [])]);
        $runId = $this->open($rule, 'occ-defer');
        self::assertNotNull($runId);

        $this->fixture->kernel->automationFanout->run(50);
        $this->fixture->kernel->automationStep->drain(50);

        self::assertSame(0, $action->effects, 'the first pass deferred');
        self::assertSame(0, $this->fixture->env->database->count(
            Query::from('idempotency_keys')->where('scope', '=', AutomationActionContext::IDEMPOTENCY_SCOPE),
        ), 'a deferral releases its claim rather than leaving it to expire');

        $steps = $this->fixture->kernel->automationQueue->forRun($runId);
        // The state written is `deferred` and not `pending`. The enum case was
        // declared and read and never written, so a queue filtered on deferred
        // matched nothing while the deferral reason sat in suppressed_reason
        // beside genuine suppressions.
        self::assertSame(AutomationStepState::Deferred, $steps[0]->state);
        self::assertSame('budget_spent', $steps[0]->suppressedReason);

        // The deferral elapses.
        $this->fixture->env->clock->set('2026-05-01 11:00:00');
        $this->fixture->kernel->automationStep->drain(50);

        self::assertSame(1, $action->effects, 'the resumed step executed exactly once');

        $this->fixture->kernel->automationStep->drain(50);
        self::assertSame(1, $action->effects, 'and a third pass did nothing');
    }

    /**
     * Delays are anchor providers over the shipped registry, never a duration
     * column, and an anchor already in the past fires immediately rather than
     * stalling the subject.
     */
    public function testADelayIsResolvedThroughTheShippedAnchorRegistry(): void
    {
        $future = new AutomationStepDefinition(
            0,
            'set_tag',
            ['tag' => 'later'],
            1,
            null,
            'absolute',
            ['at' => '2026-05-01 15:00:00'],
        );

        $rule = $this->armed('delayed', [$future]);
        $runId = $this->open($rule, 'occ-delay');
        self::assertNotNull($runId);
        $this->fixture->kernel->automationFanout->run(50);

        $steps = $this->fixture->kernel->automationQueue->forRun($runId);
        self::assertCount(1, $steps);
        self::assertSame('2026-05-01 15:00:00', $steps[0]->runAt->format('Y-m-d H:i:s'));

        // Nothing runs before the anchor.
        $this->fixture->kernel->automationStep->drain(50);
        self::assertSame(0, $this->fixture->env->database->count(Query::from('learner_tags')));

        $this->fixture->env->clock->set('2026-05-01 15:30:00');
        $this->fixture->kernel->automationStep->drain(50);
        self::assertSame(1, $this->fixture->env->database->count(Query::from('learner_tags')));
    }

    public function testAnAnchorAlreadyInThePastFiresImmediately(): void
    {
        $past = new AutomationStepDefinition(
            0,
            'set_tag',
            ['tag' => 'immediate'],
            1,
            null,
            'absolute',
            ['at' => '2020-01-01 00:00:00'],
        );

        $rule = $this->armed('past-anchor', [$past]);
        $runId = $this->open($rule, 'occ-past');
        self::assertNotNull($runId);
        $this->fixture->kernel->automationFanout->run(50);

        $steps = $this->fixture->kernel->automationQueue->forRun($runId);
        self::assertSame('2026-05-01 09:00:00', $steps[0]->runAt->format('Y-m-d H:i:s'));
    }

    /**
     * The notify action passes the automation's own occurrence key rather than
     * the literal "once", so the two dedupe digests agree on what a repetition
     * is and the notification digest never has to catch an automation retry.
     */
    public function testNotifyPassesTheAutomationsOwnOccurrenceKey(): void
    {
        // The rule re-enters on a new occurrence, because this case is about
        // two occurrences reaching the notification enqueue with two distinct
        // keys and the default `never` refuses the second entry outright.
        $rule = $this->armed('occurrence-key', [$this->step(0, 'notify', [
            'template_key' => 'vidyalms.mail.enrolled',
            'category_key' => 'course_progress',
        ])], ReentryMode::OnNewOccurrence);

        self::assertNotNull($this->open($rule, 'occ-alpha'));

        // Past the per-subject cooldown, which is on by default and is the
        // third loop protection rather than an obstacle to this case.
        $this->fixture->env->clock->set('2026-05-01 09:10:00');
        self::assertNotNull($this->open($rule, 'occ-beta'));

        $this->fixture->kernel->automationFanout->run(50);
        $this->fixture->kernel->automationStep->drain(50);

        // Only the rows the automation wrote: the fixture's own enrolment
        // mail is on the same trigger and carries the literal "once", which
        // is exactly the value an automation step must never pass on.
        $rows = $this->fixture->env->database->selectAll(
            Query::from('notification_queue')
                ->select(['occurrence_key'])
                ->whereContains('event_ref', 'sweep:')
                ->orderBy('id'),
        );
        $keys = array_map(static fn (array $row): string => (string) $row['occurrence_key'], $rows);

        self::assertContains('occ-alpha', $keys);
        self::assertContains('occ-beta', $keys);
        self::assertNotContains('once', $keys);
    }

    /**
     * The drain is cursored and its chunk is honoured.
     *
     * Adapting a chunk silently to server capacity is the named departure: an
     * operator cannot reason about a number the product will not show them, so
     * the number is the caller's, it is reported in the handler's summary line
     * and it bounds the turn.
     */
    public function testTheDrainHonoursItsChunkAndResumesOnTheNextTurn(): void
    {
        $rule = $this->armed('chunked', [$this->step(0, 'set_tag', ['tag' => 'chunked'])]);

        // Four subjects, four runs, four steps.
        foreach ([50, 10, 11, 1] as $index => $subject) {
            self::assertNotNull($this->open($rule, 'occ-chunk-' . $index, $subject));
        }

        $this->fixture->kernel->automationFanout->run(50);
        self::assertSame(4, $this->fixture->env->database->count(Query::from('automation_queue')));

        self::assertSame(2, $this->fixture->kernel->automationStep->drain(2), 'the chunk bounded the turn');
        self::assertSame(2, $this->fixture->kernel->automationStep->drain(2), 'the next turn resumed');
        self::assertSame(0, $this->fixture->kernel->automationStep->drain(2), 'and there was nothing left');

        self::assertSame(4, $this->fixture->env->database->count(
            Query::from('automation_queue')->where('state', '=', AutomationStepState::Executed->value),
        ));
    }

    // ── The notification spine is reused and not altered ────────────────

    /**
     * The automation scope is an addition to the shipped idempotency store and
     * not a change to it.
     *
     * The store's two windows are per route family, and this wave adds one
     * family. Every other caller keeps the shipped defaults, which matters
     * because a second caller already depends on them: the notification
     * template test send uses claimOrReplay as an hourly rate budget with the
     * window baked into its scope string. Both dev sites carry queued mail, so
     * a change to a window a queued row was written under is a change to mail
     * already in flight.
     */
    public function testTheAutomationScopeIsAnAdditionAndLeavesEveryOtherWindowAlone(): void
    {
        $store = $this->fixture->kernel->idempotencyStore;

        // A scope nobody configured still gets the shipped hour.
        $store->claimOrReplay('notification.test_send:1', 'k1');
        $shipped = $this->fixture->env->database->selectOne(
            Query::from('idempotency_keys')->where('scope', '=', 'notification.test_send:1'),
        );
        self::assertNotNull($shipped);
        self::assertSame('2026-05-01 10:00:00', (string) $shipped['expires_at']);

        // And the automation scope gets the queue's own lease horizon, which is
        // the whole point: a step that dies with the host process is released
        // by the lease rather than an hour later.
        $store->claimOrReplay(AutomationActionContext::IDEMPOTENCY_SCOPE, 'k2');
        $automation = $this->fixture->env->database->selectOne(
            Query::from('idempotency_keys')->where('scope', '=', AutomationActionContext::IDEMPOTENCY_SCOPE),
        );
        self::assertNotNull($automation);
        self::assertSame('2026-05-01 09:05:00', (string) $automation['expires_at']);
    }

    /**
     * A queued notification row written before this wave is untouched by it.
     *
     * The automation engine reuses notification_queue through one entry point
     * and writes nothing else there, so a row already in flight keeps its
     * digest, its state and its moment. That is asserted rather than assumed
     * because both development sites carry a backlog.
     */
    public function testAQueuedNotificationRowIsUnchangedByAnAutomationRun(): void
    {
        $before = $this->fixture->env->database->selectAll(
            Query::from('notification_queue')->select(['id', 'dedupe_key', 'state', 'run_at'])->orderBy('id'),
        );
        self::assertNotSame([], $before, 'the fixture already queued mail, which is what makes this meaningful');

        $rule = $this->armed('spine', [$this->step(0, 'set_tag', ['tag' => 'spine'])]);
        self::assertNotNull($this->open($rule, 'occ-spine'));
        $this->fixture->kernel->automationFanout->run(50);
        $this->fixture->kernel->automationStep->drain(50);

        $after = $this->fixture->env->database->selectAll(
            Query::from('notification_queue')->select(['id', 'dedupe_key', 'state', 'run_at'])->orderBy('id'),
        );

        self::assertSame($before, array_slice($after, 0, \count($before)));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

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
    private function armed(
        string $key,
        array $steps,
        ReentryMode $reentry = ReentryMode::Never,
    ): AutomationRule {
        $rule = $this->fixture->kernel->manageAutomationRules->save($this->fixture->manager(), new AutomationRule(
            null,
            $key,
            ucfirst($key),
            self::TRIGGER,
            null,
            $steps,
            $reentry,
        ));

        return $this->fixture->kernel->manageAutomationRules->arm($this->fixture->manager(), $rule->id ?? 0);
    }

    private function open(AutomationRule $rule, string $occurrenceKey, int $subjectId = 50): ?int
    {
        return $this->fixture->kernel->openAutomationRuns->forRule(
            $rule,
            $rule->triggerKey,
            TriggerOccurrence::forSweep(self::TRIGGER, $subjectId, $occurrenceKey, 'user', $subjectId),
        );
    }
}
