<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Notification\ConditionsStillHold;
use Shondalai\VidyaLMS\Core\Application\Shared\IdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationActionRecord;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRunState;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStep;
use Shondalai\VidyaLMS\Core\Domain\Automation\SuppressionReason;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationActionLogRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRunRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionDisposition;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresIdempotencyDiscriminator;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailureDisposition;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\ProducesReviewItem;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * One queued step, executed once.
 *
 * The order is the whole of the design's idempotency specification and it is
 * not negotiable: **the claim is written to idempotency_keys before the
 * effect, in the same transaction as the effect's own enqueue**, so a retry
 * finds the claim and replays the stored outcome rather than doing the thing
 * again. Following Stripe's contract, the result is stored only once execution
 * has begun, so a params-validation failure stays retryable; the first outcome
 * is replayed verbatim on a retry.
 *
 * **A ConflictException from claimOrReplay maps to deferred with
 * next_attempt_at set to the claim expiry, and never to failed_permanent.** A
 * claim that is in flight belongs to another runner, and both hosts are
 * documented multi-firers, so a second runner over one row is the normal case.
 *
 * **A policy deferral explicitly releases the claim** rather than leaving it to
 * expire. The published rule says the result is stored only once execution has
 * begun, and a claim left behind by a deferral would be found by the step's own
 * resumption and short-circuited to "already done": the effect would never
 * happen and nobody would be told.
 *
 * A step killed between the claim and the effect is recovered by the queue's
 * lease, which is why the automation scope's claim window is the lease horizon
 * rather than a wall clock: the transaction rolls the claim back with the
 * effect, the lease returns the row to pending, and exactly one effect happens
 * after the lease expires.
 *
 * **The two sentences above were true of the design and false of this class
 * until R6 closed it.** claimOrReplay, execute and complete were three
 * separate commits and reclaimExpiredLeases touched only automation_queue, so
 * a step killed after its effect and before its outcome came back through the
 * lease, found no claim, and ran the effect a second time. The nine free
 * actions masked it by re-reading their own state first; a third-party action
 * on this seam did not.
 */
final class RunAutomationStep
{
    public function __construct(
        private readonly SqlAutomationQueueRepository $queue,
        private readonly SqlAutomationRuleRepository $rules,
        private readonly SqlAutomationRunRepository $runs,
        private readonly SqlAutomationActionLogRepository $log,
        private readonly AutomationActionRegistry $actions,
        private readonly RetryPolicyRegistry $retries,
        private readonly FailurePolicyRegistry $failures,
        private readonly IdempotencyStore $idempotency,
        private readonly ClockPort $clock,
        private readonly \Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard $access,
        private readonly ?ConditionsStillHold $conditions = null,
        /**
         * The transaction the claim and the effect commit inside.
         *
         * Optional only so a harness that predates it still compiles; the
         * kernel always supplies one, and without it the class does what its
         * docblock said it did not: writes the claim, runs the effect, and
         * completes the claim in three separate commits.
         */
        private readonly ?DatabasePort $database = null,
        /** The frame the engine pushes so a run opened from inside inherits it. */
        private readonly ?AutomationCascade $cascade = null,
    ) {
    }

    /**
     * Drains due steps, cursored, with an observable chunk size.
     *
     * The chunk is the caller's rather than adapted silently to server
     * capacity: an operator cannot reason about a number the product will not
     * show them.
     */
    public function drain(int $limit = 25): int
    {
        $this->queue->reclaimExpiredLeases($limit);
        $token = bin2hex(random_bytes(16));
        $done = 0;
        $cursor = 0;

        // The cursor is the row id, which is stable and unique, so a page
        // whose rows another runner claimed does not make this one read the
        // same rows again: it moves past them. An offset would make the
        // engine build and discard every skipped row and would let rows
        // shift between pages while the table is being written.
        while ($done < $limit) {
            $page = $this->queue->due($limit, $cursor);

            if ($page === []) {
                break;
            }

            foreach ($page as $step) {
                $cursor = max($cursor, $step->id ?? 0);

                if ($done >= $limit || !$this->queue->claim($step->id ?? 0, $token)) {
                    continue;
                }

                $this->execute($step);
                ++$done;
            }
        }

        return $done;
    }

    /** One claimed step. */
    public function execute(AutomationStep $step): ActionOutcome
    {
        $queueId = $step->id ?? 0;
        $rule = $this->rules->find($step->ruleId);
        $run = $this->runs->find($step->runId);

        if ($rule === null || $run === null || !$this->actions->has($step->actionKey)) {
            $this->queue->markSuppressed($queueId, 'unresolvable');

            return ActionOutcome::suppressed('unresolvable');
        }

        $definition = null;

        foreach ($this->rules->stepsFor($step->ruleId, $step->ruleVersion) as $candidate) {
            if ($candidate->stepIndex === $step->stepIndex) {
                $definition = $candidate;
            }
        }

        if ($definition === null) {
            $this->queue->markSuppressed($queueId, 'unresolvable');

            return ActionOutcome::suppressed('unresolvable');
        }

        $action = $this->actions->get($step->actionKey);

        if (!$action instanceof AutomationActionInterface) {
            $this->queue->markSuppressed($queueId, 'unresolvable');

            return ActionOutcome::suppressed('unresolvable');
        }

        // Every action in the builder supports a rehearsal; one that does not
        // may not appear there at all, so a stored step naming a non-rehearsing
        // action on a dry run is suppressed rather than executed for real.
        if ($step->dryRun && !$action instanceof SupportsDryRun) {
            $this->queue->markSuppressed($queueId, SuppressionReason::DRY_RUN);

            return ActionOutcome::suppressed(SuppressionReason::DRY_RUN);
        }

        // The run's own authoriser, captured when the intent row was
        // written, so a rule edited or disarmed mid-flight does not leave a
        // step running under nobody.
        $actorId = $run->actorUserId ?? $rule->authorisingUserId() ?? 0;

        if ($actorId < 1) {
            $this->queue->markSuppressed($queueId, 'no_authoriser');

            return ActionOutcome::suppressed('no_authoriser');
        }

        $actor = Principal::user($actorId);
        if (!$this->access->can($actor, \Shondalai\VidyaLMS\Core\Application\Shared\Capability::MANAGE)
            && !$this->access->can($actor, \Shondalai\VidyaLMS\Core\Application\Shared\Capability::ADMIN)) {
            $this->queue->markSuppressed($queueId, 'authoriser_revoked');
            return ActionOutcome::suppressed('authoriser_revoked');
        }
        $attempt = $step->attempts + 1;

        $context = AutomationActionContext::forStep(
            $step->ruleId,
            $step->ruleVersion,
            $step->runId,
            $step->stepIndex,
            $step->actionKey,
            $step->occurrenceKey,
            $actor,
            $this->clock->now(),
            $this->clock->now()->modify('+' . SqlAutomationQueueRepository::LEASE_SECONDS . ' seconds'),
            $step->subjectType,
            $step->subjectId,
            $rule->organisationId,
            $step->dryRun,
            $attempt,
            $step->cascadeDepth,
            $run->chain()->ruleIds,
            $step->params,
            $step->recipientUserId ?? $run->recipientUserId,
        );

        if ($action instanceof DeclaresIdempotencyDiscriminator) {
            $context = $context->withDiscriminator($action->idempotencyDiscriminator($context));
        }

        // The row is the authority on what one effect is. The fan-out applied
        // the same discriminator when it wrote the row, so these agree; when
        // a provider is not deterministic the stored key wins, because a claim
        // taken under a key the queue row does not carry is a ledger and a
        // queue disagreeing about one effect.
        if ($step->idemKey !== '' && $step->idemKey !== $context->idempotencyDigest) {
            $context = $context->withStoredDigest($step->idemKey);
        }

        if (!$this->guardHolds($definition->guardConditions, $context, $rule->courseId)) {
            $this->queue->markSuppressed($queueId, SuppressionReason::GUARD_FAILED);
            $this->countOnRule($step, 'suppressed_count');
            $this->runs->addToCounter($step->runId, 'skipped_count');

            return ActionOutcome::suppressed(SuppressionReason::GUARD_FAILED);
        }

        return $this->claimAndRun(
            $action,
            $context,
            $step,
            $definition->retryPolicyKey,
            $definition->failurePolicyKey,
            $definition->producesReviewItem || $action instanceof ProducesReviewItem,
        );
    }

    private function claimAndRun(
        AutomationActionInterface $action,
        AutomationActionContext $context,
        AutomationStep $step,
        string $retryKey,
        string $failureKey,
        bool $parksAfterwards,
    ): ActionOutcome {
        $queueId = $step->id ?? 0;
        $scope = AutomationActionContext::IDEMPOTENCY_SCOPE;
        $key = $context->idempotencyDigest;

        $claimed = function () use ($action, $context, $step, $retryKey, $failureKey, $parksAfterwards, $scope, $key): ActionOutcome {
            $queueId = $step->id ?? 0;
            $replay = $this->idempotency->claimOrReplay($scope, $key);

            if ($replay !== null) {
                // The first outcome, replayed verbatim. Nothing runs a second
                // time, and the audit already carries the row the first
                // execution wrote.
                $this->queue->markExecuted($queueId, $step->attempts + 1);

                return ActionOutcome::executed([], $replay);
            }

            $this->queue->markExecuting($queueId);
            $outcome = $this->cascade === null
                ? $action->execute($context)
                : $this->cascade->within($context, static fn (): ActionOutcome => $action->execute($context));

            return $this->settle($outcome, $context, $step, $retryKey, $failureKey, $parksAfterwards);
        };

        try {
            // **The claim, the effect and the outcome commit together.** The
            // docblock said so from the first line and the code did none of
            // it: claimOrReplay, execute and complete were three separate
            // commits, so a step killed between the effect and the outcome
            // came back through the lease with no claim to find and ran the
            // effect a second time. The nine free actions masked it by
            // re-reading their own state first; a third-party action
            // registered on this seam does not.
            return $this->database === null ? $claimed() : $this->database->transactional($claimed);
        } catch (ConflictException) {
            // Another runner holds the claim. Deferred, never failed: the
            // ledger row is the authority and it will either complete or its
            // lease will release it.
            $next = $this->clock->now()->modify('+' . SqlAutomationQueueRepository::LEASE_SECONDS . ' seconds');
            $this->queue->markDeferred($queueId, $next, 'claim_in_flight');

            return ActionOutcome::deferred($next, 'claim_in_flight');
        } catch (\Throwable $failure) {
            // The transaction rolled back, so the claim the failed attempt
            // took never existed and the release below is a no-op that keeps
            // the untransacted path honest.
            $this->idempotency->release($scope, $key);

            return $this->fail(
                ActionOutcome::failed('action_threw', $failure->getMessage()),
                $context,
                $step,
                $retryKey,
                $failureKey,
            );
        }
    }

    private function settle(
        ActionOutcome $outcome,
        AutomationActionContext $context,
        AutomationStep $step,
        string $retryKey,
        string $failureKey,
        bool $parksAfterwards,
    ): ActionOutcome {
        $queueId = $step->id ?? 0;
        $scope = AutomationActionContext::IDEMPOTENCY_SCOPE;
        $key = $context->idempotencyDigest;
        $attempt = $context->attempt;

        if ($outcome->disposition === ActionDisposition::Deferred) {
            // The deferral releases the claim explicitly. A claim left behind
            // would be found by the step's own resumption and short-circuited
            // to "already done", so the effect would never happen.
            $this->idempotency->release($scope, $key);
            $next = $outcome->nextAttemptAt ?? $this->clock->now()->modify('+1 hour');
            $this->queue->markDeferred($queueId, $next, $outcome->reason ?? 'deferred');

            return $outcome;
        }

        if ($outcome->disposition === ActionDisposition::Suppressed) {
            $this->idempotency->release($scope, $key);
            $this->queue->markSuppressed($queueId, $outcome->reason ?? SuppressionReason::GUARD_FAILED);
            $this->countOnRule($step, 'suppressed_count');
            $this->runs->addToCounter($step->runId, 'skipped_count');
            $this->record($outcome, $context, $step, false);

            return $outcome;
        }

        if ($outcome->disposition === ActionDisposition::Executed) {
            $this->idempotency->complete($scope, $key, $outcome->afterState);
            $this->record($outcome, $context, $step, true);
            $this->countOnRule($step, 'executed_count');
            $this->runs->addToCounter($step->runId, 'executed_count');

            if ($parksAfterwards) {
                // The outcome is parked for a named person AFTER the effect,
                // which is the other half of the capability that was doing two
                // jobs. The step is executed and its review item waits.
                $this->queue->markParked($queueId, $attempt, 'review_item');

                return $outcome;
            }

            $this->queue->markExecuted($queueId, $attempt);

            return $outcome;
        }

        return $this->fail($outcome, $context, $step, $retryKey, $failureKey);
    }

    private function fail(
        ActionOutcome $outcome,
        AutomationActionContext $context,
        AutomationStep $step,
        string $retryKey,
        string $failureKey,
    ): ActionOutcome {
        $queueId = $step->id ?? 0;
        $attempt = $context->attempt;
        $this->idempotency->release(AutomationActionContext::IDEMPOTENCY_SCOPE, $context->idempotencyDigest);
        $this->record($outcome, $context, $step, false);
        $this->countOnRule($step, 'failed_count');
        $this->runs->addToCounter($step->runId, 'failed_count');

        $plan = $this->retries->has($retryKey) ? $this->retries->get($retryKey)->plan() : null;
        $delay = $outcome->retryable && $plan !== null ? $plan->delayBefore($attempt + 1) : null;

        if ($delay !== null) {
            $jitter = $plan !== null && $plan->jitter && $delay > 0 ? random_int(0, (int) ceil($delay / 10)) : 0;
            $next = $this->clock->now()->modify('+' . ($delay + $jitter) . ' seconds');
            $this->queue->markRetry($queueId, $attempt, $next, $outcome->message ?? ($outcome->reason ?? 'failed'));

            return $outcome;
        }

        $disposition = $this->failures->has($failureKey)
            ? $this->failures->get($failureKey)->disposition()
            : FailureDisposition::Park;

        $error = $outcome->message ?? ($outcome->reason ?? 'failed');

        if ($disposition === FailureDisposition::Park) {
            $this->queue->markParked($queueId, $attempt, $error);

            return $outcome;
        }

        $this->queue->markFailedPermanent($queueId, $attempt, $error);

        if ($disposition === FailureDisposition::Halt) {
            // Halt ends the run: later steps do not execute, which is what
            // separates it from continue.
            foreach ($this->queue->forRun($step->runId) as $sibling) {
                if ($sibling->stepIndex > $step->stepIndex && !$sibling->state->isTerminal()) {
                    $this->queue->markSuppressed($sibling->id ?? 0, 'run_halted');
                }
            }

            $this->runs->transition($step->runId, AutomationRunState::Halted);
        }

        return $outcome;
    }

    /**
     * A rehearsal moves no counter the rules screen shows.
     *
     * The four automation_rules counters are the number of real executions,
     * and the design's own rule is that rehearsing a rule costs the subject
     * nothing. They used to move on a dry run, so previewing a rule twice read
     * back as two queued and two executed with no real run behind either, and
     * the number the list screen showed was not the number of executions.
     */
    private function countOnRule(AutomationStep $step, string $column): void
    {
        if ($step->dryRun) {
            return;
        }

        $this->rules->incrementCounter($step->ruleId, $column);
    }

    private function record(
        ActionOutcome $outcome,
        AutomationActionContext $context,
        AutomationStep $step,
        bool $success,
    ): void {
        $this->log->record(new AutomationActionRecord(
            $step->id,
            $step->runId,
            $step->ruleId,
            $step->ruleVersion,
            $step->stepIndex,
            $step->actionKey,
            $step->subjectType,
            $step->subjectId,
            // The identity the step ran under, per action. Never a literal
            // system user: a step gated under one business identity and
            // completed under another is undetectable after the fact, and
            // recording the authoriser beside the subject is what closes it.
            $context->principal->userId,
            $context->idempotencyDigest,
            $success,
            $this->clock->now(),
            $outcome->beforeState,
            $outcome->afterState,
            1,
            $success ? null : $outcome->reason,
            $success ? null : $outcome->message,
        ));
    }

    private function guardHolds(
        ?\Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree $guard,
        AutomationActionContext $context,
        ?int $courseId,
    ): bool {
        if ($guard === null || $this->conditions === null) {
            return true;
        }

        // The person the step acts for, and the subject only when the subject
        // is a person. Reading subject_id as a user id evaluated a guard tree
        // as Principal::user(courseId), which is a different learner or none.
        $availability = AvailabilityContext::create(
            Principal::user($context->actsForUserId() ?? $context->principal->userId),
            AvailabilitySurface::Automation,
            $context->now,
        );

        return $this->conditions->hold($guard, $availability, $courseId);
    }
}
