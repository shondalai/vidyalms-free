<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Notification\ConditionsStillHold;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRun;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRunState;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStep;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepDefinition;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepState;
use Shondalai\VidyaLMS\Core\Domain\Automation\SuppressionReason;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRunRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresIdempotencyDiscriminator;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\RequiresApprovalBeforeEffect;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;

/**
 * The second hop: an intent row becomes one queue row per step.
 *
 * It is binned exactly as the notification fan-out is, so one run does bounded
 * work, and it re-evaluates the rule's entry tree here rather than at the
 * trigger, because a seven-day nudge conditioned on "has not completed" must
 * not act on a learner who finished on day two.
 *
 * A registry miss on any action, retry policy or failure policy the rule names
 * moves the rule to degraded and stops the fan-out. It never reaches the
 * failure policy axis: a miss is a rule-level condition, because the other
 * reading lets the free half of a rule keep running while an access revocation
 * silently never happens.
 */
final class RunAutomationFanout
{
    public const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly SqlAutomationRunRepository $runs,
        private readonly SqlAutomationRuleRepository $rules,
        private readonly SqlAutomationQueueRepository $queue,
        private readonly AutomationActionRegistry $actions,
        private readonly RetryPolicyRegistry $retries,
        private readonly FailurePolicyRegistry $failures,
        private readonly AutomationLoopGuard $loops,
        private readonly ClockPort $clock,
        private readonly \Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard $access,
        private readonly ?ConditionsStillHold $conditions = null,
        private readonly ?ScheduleAnchorRegistry $anchors = null,
        /** Wakes the step drain for each row written, rather than waiting a bin. */
        private readonly ?AutomationJobs $jobs = null,
        /**
         * The zone an anchor's arithmetic runs in. It was absent, so every
         * delay resolved against UTC while the notification resolver passed
         * the site's own zone: two consumers of one anchor vocabulary
         * disagreeing about the calendar.
         */
        private readonly string $siteTimezone = 'UTC',
    ) {
    }

    /** How many queue rows this turn wrote. */
    public function run(int $limit = self::DEFAULT_LIMIT, int $binIndex = 0, int $binCount = 1): int
    {
        $this->runs->reclaimExpiredFanouts($limit);
        $token = bin2hex(random_bytes(16));
        $written = 0;

        foreach ($this->runs->claimIntents($token, $limit, $binIndex, $binCount) as $run) {
            $written += $this->expand($run);
        }

        return $written;
    }

    /** One intent row, expanded into its steps. */
    public function expand(AutomationRun $run): int
    {
        $rule = $this->rules->find($run->ruleId);

        if ($rule === null) {
            $this->runs->transition($run->id ?? 0, AutomationRunState::Cancelled);

            return 0;
        }

        $missing = $this->missingKeys($rule);

        if ($missing !== []) {
            // The rules screen names the missing key, and the rule does not
            // fan out. Never a step failure: a miss is a rule-level condition.
            $this->rules->markState($rule->id ?? 0, \Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRuleState::Degraded);
            $this->runs->appendLog($run->id ?? 0, 'degraded: ' . implode(', ', $missing));
            $this->runs->transition($run->id ?? 0, AutomationRunState::Cancelled);

            return 0;
        }

        if (!$this->entryConditionsHold($rule, $run)) {
            $this->runs->appendLog($run->id ?? 0, 'suppressed: ' . SuppressionReason::CONDITIONS_NO_LONGER_HOLD);
            $this->runs->addToCounter($run->id ?? 0, 'skipped_count');
            $this->runs->transition($run->id ?? 0, AutomationRunState::Completed);

            return 0;
        }

        // The steps of the version this run started on, never the rule's
        // current version: a run already executing keeps the version it
        // started on, which is why automation_queue carries rule_version.
        $steps = $this->rules->stepsFor($run->ruleId, $run->ruleVersion);
        $written = 0;

        foreach ($steps as $step) {
            $written += $this->enqueue($rule, $run, $step) === null ? 0 : 1;
        }

        $this->runs->addToCounter($run->id ?? 0, 'matched_count');
        $this->runs->transition($run->id ?? 0, AutomationRunState::Running);

        return $written;
    }

    /**
     * Every key this rule names that no registry resolves.
     *
     * @return list<string>
     */
    public function missingKeys(AutomationRule $rule): array
    {
        $missing = [];

        foreach ($rule->steps as $step) {
            if (!$this->actions->has($step->actionKey)) {
                $missing[] = 'action:' . $step->actionKey;
            }

            if (!$this->retries->has($step->retryPolicyKey)) {
                $missing[] = 'retry:' . $step->retryPolicyKey;
            }

            if (!$this->failures->has($step->failurePolicyKey)) {
                $missing[] = 'failure:' . $step->failurePolicyKey;
            }

            // The anchor key was not checked at all, so uninstalling the
            // package that owns a delay anchor turned every delayed step into
            // run-now: a thirty day delay fanned out at the trigger instant
            // with the rule still reported enabled. The seam's own docblock
            // publishes the opposite, that an unresolved anchor leaves the
            // thing blocked, and names it the one registry miss whose obvious
            // reading is the dangerous one.
            if (
                $step->delayAnchorKey !== null
                && $step->delayAnchorKey !== ''
                && ($this->anchors === null || !$this->anchors->has($step->delayAnchorKey))
            ) {
                $missing[] = 'anchor:' . $step->delayAnchorKey;
            }
        }

        return $missing;
    }

    /**
     * Whether this subject falls inside the rule's declared scope.
     *
     * Only the course scope is enforced, and the two tenancy-shaped scopes are
     * refused at save until the read that would enforce them lands, because
     * the columns were accepted, stored and presented while nothing in the
     * whole engine read them: a rule scoped to one organisation acted on a
     * learner who belonged to none.
     */
    private function withinScope(AutomationRule $rule, AutomationRun $run): bool
    {
        if ($rule->courseId === null) {
            return true;
        }

        // A run whose subject is the course itself is in scope when it is that
        // course, and a run about a person carries no course to compare, so it
        // passes and the entry tree is what narrows it.
        return $run->subjectType !== 'course' || $run->subjectId === null || $run->subjectId === $rule->courseId;
    }

    private function enqueue(AutomationRule $rule, AutomationRun $run, AutomationStepDefinition $step): ?int
    {
        $runId = $run->id ?? 0;

        // The arming digest as a control rather than a record. A rule whose
        // stored shape no longer matches what a person armed does not enqueue
        // a step, which is what three docblocks and the schema comment already
        // said while nothing anywhere called this. A rehearsal is exempt: a
        // preview of an unarmed rule is exactly what a preview is for.
        if (!$run->dryRun && !ManageAutomationRules::armingStillHolds($rule)) {
            $this->runs->appendLog($runId, 'suppressed: ' . SuppressionReason::ARMING_MOVED);
            $this->runs->addToCounter($runId, 'skipped_count');

            return null;
        }

        if (!$this->withinScope($rule, $run)) {
            $this->runs->appendLog($runId, 'suppressed: ' . SuppressionReason::OUT_OF_SCOPE);
            $this->runs->addToCounter($runId, 'skipped_count');

            return null;
        }
        // The identity the run started under, and never the rule's current
        // one. A run keeps the version it started on and it keeps the
        // authoriser it started under for the same reason: an edit that
        // cleared the arming must not leave an in-flight run with no named
        // person, and the audit gap the idempotency literature names is a
        // step gated under one identity and completed under another.
        $actorId = $run->actorUserId ?? $rule->authorisingUserId() ?? 0;

        if ($actorId < 1) {
            $this->runs->appendLog($runId, 'suppressed: no_authoriser');
            $this->runs->addToCounter($runId, 'skipped_count');

            return null;
        }

        $actor = Principal::user($actorId);
        if (!$this->access->can($actor, \Shondalai\VidyaLMS\Core\Application\Shared\Capability::MANAGE)
            && !$this->access->can($actor, \Shondalai\VidyaLMS\Core\Application\Shared\Capability::ADMIN)) {
            $this->runs->appendLog($runId, 'suppressed: authoriser_revoked');
            $this->runs->addToCounter($runId, 'skipped_count');
            return null;
        }

        $context = AutomationActionContext::forStep(
            $rule->id ?? 0,
            $run->ruleVersion,
            $runId,
            $step->stepIndex,
            $step->actionKey,
            $run->occurrenceKey,
            $actor,
            $this->clock->now(),
            $this->clock->now()->modify('+1 day'),
            $run->subjectType,
            $run->subjectId,
            $rule->organisationId,
            $run->dryRun,
            1,
            $run->cascadeDepth,
            $run->chain()->ruleIds,
            $step->params,
            $run->recipientUserId,
        );

        // The discriminator is applied here, where the action is resolvable
        // from the registry, so the queue row's two digests are the key the
        // ledger claim is actually taken under. It used to be applied only in
        // the step machine, after the row existed, so idem_key named one
        // effect and the claim named another while the column comment said
        // the ledger and the queue agree on what one effect is.
        $action = $this->actions->has($step->actionKey) ? $this->actions->get($step->actionKey) : null;

        if ($action instanceof DeclaresIdempotencyDiscriminator) {
            $context = $context->withDiscriminator($action->idempotencyDiscriminator($context));
        }

        // Loop protection mechanism two, checked before every enqueue and not
        // only when the run is opened: a run whose depth is already at the
        // site ceiling writes no step at all, so a cascade stops at the row
        // it would have written rather than at the effect it would have had.
        if ($run->cascadeDepth >= $this->loops->ceiling()) {
            $this->runs->appendLog($runId, 'suppressed: ' . SuppressionReason::CASCADE_CEILING);
            $this->runs->addToCounter($runId, 'skipped_count');

            return null;
        }

        $runAt = $this->runAtFor($step, $actor);

        if ($runAt === null) {
            // An anchor that resolves to nothing holds the step rather than
            // releasing it, which is the direction the seam publishes.
            $this->runs->appendLog($runId, 'suppressed: ' . SuppressionReason::ANCHOR_UNRESOLVED);
            $this->runs->addToCounter($runId, 'skipped_count');

            return null;
        }

        $id = $this->queue->enqueue(new AutomationStep(
            null,
            $runId,
            $rule->id ?? 0,
            $run->ruleVersion,
            $step->stepIndex,
            $step->actionKey,
            $run->subjectType,
            $run->subjectId,
            $step->params,
            $step->paramsVersion,
            $run->occurrenceKey,
            $context->idempotencyDigest,
            $context->idempotencyDigest,
            $runAt,
            // The stored column OR the action's own declaration. Only the HTTP
            // mapper ever derived the column, so a rule saved through the use
            // case by an importer or a course copy stored zero and the gate
            // never fired: the effect ran with nobody approving. This is the
            // same OR the review item already used one method along.
            $step->requiresApprovalBeforeEffect || $action instanceof RequiresApprovalBeforeEffect
                ? AutomationStepState::AwaitingApproval
                : AutomationStepState::Pending,
            0,
            null,
            $run->cascadeDepth,
            $run->dryRun,
            recipientUserId: $run->recipientUserId,
        ));

        if ($id !== null && !$run->dryRun) {
            // A rehearsal costs the subject nothing, and that includes the
            // number the rules screen shows: the counters used to move on a
            // preview, so rehearsing a rule twice read back as two queued and
            // two executed with no real run behind either.
            $this->rules->incrementCounter($rule->id ?? 0, 'queued_count');
        }

        if ($id !== null) {
            $this->jobs?->queueStep($context->idempotencyDigest, $runAt);
        }

        return $id;
    }

    /**
     * When the step may first run: now, or the instant its delay anchor
     * resolves to.
     *
     * Delays are anchor providers rather than a duration column, so a seventh
     * delay kind is a package. One runtime rule is copied verbatim because
     * authors get it wrong otherwise: an anchor already in the past fires
     * immediately rather than stalling the subject.
     *
     * The second rule as first written was the dangerous reading of the miss:
     * an anchor that could not be resolved released the step at once, so
     * uninstalling the package that owned it turned every delayed step into
     * run-now. The seam's own docblock publishes the opposite and names it the
     * one registry miss whose obvious reading is the dangerous one, so an
     * unresolvable anchor now answers null and the step is held.
     */
    private function runAtFor(AutomationStepDefinition $step, Principal $actor): ?\DateTimeImmutable
    {
        $now = $this->clock->now();

        if ($step->delayAnchorKey === null || $step->delayAnchorKey === '') {
            return $now;
        }

        if ($this->anchors === null || !$this->anchors->has($step->delayAnchorKey)) {
            // Unreachable through the fan-out, because missingKeys() has
            // already degraded the rule, and kept as the second wall: a step
            // whose anchor nobody answers is held, never released.
            return null;
        }

        $anchor = $this->anchors->get($step->delayAnchorKey);
        $context = \Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext::create(
            $actor,
            $now,
            null,
            null,
            $step->delay,
            $this->siteTimezone,
        );

        // The zone the anchor's own arithmetic runs in, asked for rather than
        // assumed: "N days" is done in the anchor's local calendar and never
        // by adding N times 86400 seconds, or a DST transition lands day seven
        // an hour early. The notification resolver already asked; this one
        // did not, so one anchor vocabulary had two consumers disagreeing.
        $zone = $anchor->timezoneFor($context);

        if ($zone !== '' && $zone !== $this->siteTimezone) {
            $context = \Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext::create(
                $actor,
                $now,
                null,
                null,
                $step->delay,
                $zone,
            );
        }

        $resolved = $anchor->resolve($context);

        if ($resolved === null) {
            return null;
        }

        return $resolved < $now ? $now : $resolved;
    }

    private function entryConditionsHold(AutomationRule $rule, AutomationRun $run): bool
    {
        if ($rule->conditions === null || $this->conditions === null) {
            return true;
        }

        // The person the occurrence is about, and the subject only when the
        // subject is a person. Reading subject_id as a user id evaluated an
        // entitlement-expiry rule's entry tree as Principal::user(courseId),
        // which is a different learner or none at all.
        $context = AvailabilityContext::create(
            Principal::user($run->actsForUserId() ?? ($rule->authorisingUserId() ?? 0)),
            AvailabilitySurface::Automation,
            $this->clock->now(),
        );

        return $this->conditions->hold($rule->conditions, $context, $rule->courseId);
    }
}
