<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationActionRecord;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStep;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepState;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationActionLogRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRunRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * A named person releases a step that is waiting for one.
 *
 * **This is the missing half of the human-in-the-loop guarantee.** The park
 * half shipped and the release half did not: `markAwaitingApproval` had no
 * caller, `due()` read only pending, the lease reclaim walked only claimed and
 * executing, and nothing in the fourteen routes moved a row out of
 * `awaiting_approval`. A step gated before its effect therefore waited for
 * ever, and the only exit was cancelling the whole rule by disarming or
 * archiving it. Park, which is the default failure policy for every
 * non-reversible action, had exactly the same problem from the other end: a
 * parked run was "resumable" with nothing that could resume it.
 *
 * The release names the person, and it says so in the audit before the step
 * runs rather than after. An approval nobody can attribute is the audit gap
 * the arming columns exist to close, one table along.
 */
final class ReleaseAutomationStep
{
    public function __construct(
        private readonly SqlAutomationQueueRepository $queue,
        private readonly SqlAutomationRunRepository $runs,
        private readonly SqlAutomationActionLogRepository $log,
        private readonly ClockPort $clock,
        private readonly AccessGuard $guard,
        /** Woken the moment the row goes back to pending, so a release acts now. */
        private readonly ?AutomationJobs $jobs = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Principal $actor, int $ruleId, int $runId, int $stepIndex, ?string $note = null): array
    {
        $this->guard->requireAny($actor, Capability::MANAGE, Capability::ADMIN);

        $run = $this->runs->find($runId);

        if ($run === null || $run->ruleId !== $ruleId) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRun', $runId);
        }

        $step = $this->stepOf($runId, $stepIndex);

        if ($step === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationStep', $stepIndex);
        }

        if (!self::isReleasable($step->state)) {
            throw ValidationException::forField(
                'state',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('A step in "%s" is not waiting for anybody to release it.', $step->state->value),
            );
        }

        $now = $this->clock->now();

        if (!$this->queue->release($step->id ?? 0, $now)) {
            // Another reviewer got there first. The conditional move is the
            // authority, so this is a refusal and never a second release.
            throw ValidationException::forField('state', 'This step has already been released.');
        }

        $this->log->record(new AutomationActionRecord(
            $step->id,
            $step->runId,
            $step->ruleId,
            $step->ruleVersion,
            $step->stepIndex,
            $step->actionKey,
            $step->subjectType,
            $step->subjectId,
            // The releasing person, and never the rule's authoriser: what this
            // row records is who released the gate, which is a different act
            // from who armed the rule.
            $actor->userId,
            $step->idemKey,
            true,
            $now,
            ['state' => $step->state->value],
            $note === null || trim($note) === ''
                ? ['released_to' => AutomationStepState::Pending->value]
                : ['released_to' => AutomationStepState::Pending->value, 'note' => trim($note)],
        ));

        $this->jobs?->queueStep($step->idemKey, $now);

        return [
            'rule_id' => $ruleId,
            'run_id' => $runId,
            'step_index' => $stepIndex,
            'released_from' => $step->state->value,
            'state' => AutomationStepState::Pending->value,
            'released_by' => $actor->userId,
            'released_at' => $now->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * The two states a person can release from, named here so the route, the
     * review queue and the refusal cannot drift into three answers.
     */
    public static function isReleasable(AutomationStepState $state): bool
    {
        return $state === AutomationStepState::AwaitingApproval || $state === AutomationStepState::Parked;
    }

    private function stepOf(int $runId, int $stepIndex): ?AutomationStep
    {
        foreach ($this->queue->forRun($runId) as $step) {
            if ($step->stepIndex === $stepIndex) {
                return $step;
            }
        }

        return null;
    }
}
