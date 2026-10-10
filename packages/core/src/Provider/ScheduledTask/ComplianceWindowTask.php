<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceState;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceStatus;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceSubject;
use Shondalai\VidyaLMS\Core\Domain\Compliance\RenewalStatus;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\ComplianceStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\ComplianceRule\ComplianceRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\ComplianceContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Re-evaluating every open compliance assignment, once a day.
 *
 * **It performs no destructive reset, and that is the free half of the task
 * rather than a stub.** The design's recertification window reset resets course
 * and activity completion for a whole pathway, including work recognised from
 * prior learning, on a timer rather than by an administrator's action, which
 * makes it the highest-risk operation in the model. It is Business machinery
 * because it needs a Business rule to tell it when a window opens, and no rule
 * ships free. What is free is the walk: every assignment is re-asked, the answer
 * is written back, and the answer for a `none` rule is assigned and untracked.
 *
 * **The registry miss is the point of running it at all in the free build.** A
 * seam whose only exercise is a unit test is a seam nobody notices has broken;
 * this task asks {@see ComplianceRuleRegistry::evaluateThrough()} once per open
 * assignment per day, so the day a Pro rule is registered the answers change
 * with no core edit, and until then every answer is provably not compliant.
 *
 * **It is also the completion listener, because there was not one.**
 * `ComplianceState::dateColumns()` says `completed_at` is "written by the
 * completion listener" and nothing in the product wrote it, so the column was
 * null on every row that had ever existed and no rule could answer compliant
 * about a real person however well they had done. The walk now asks, per open
 * assignment whose requirement names a course, whether that person's enrolment
 * has completed, and stamps the instant the enrolment carries. It discovers a
 * completion and never manufactures one.
 *
 * The key is spelled the way the other fifteen shipped tasks are, not the
 * `org.*` the design's table first published: a host job row stores this string
 * and both drains resolve a handler by it, so it is not renameable after
 * shipping.
 */
final class ComplianceWindowTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    /** What one assignment write did, for the run summary. */
    private const UNCHANGED = 'unchanged';
    private const WRITTEN = 'written';
    private const REFUSED = 'refused';

    /** The compliance word was refused and the renewal word was written. */
    private const STATUS_REFUSED = 'status_refused';

    /** The renewal word was refused and the compliance word was written. */
    private const RENEWAL_REFUSED = 'renewal_refused';

    public const KEY = 'vidyalms.organisation.compliance_window';

    /** How many assignments one run re-evaluates, so a large site drains over several. */
    public const BATCH = 500;

    /**
     * The statuses a run still has something to say about.
     *
     * A person who is exempt or whose assignment is suspended is answered by a
     * decision somebody recorded, and re-asking a rule about them would let an
     * automated evaluation overwrite a human one, which is the direction GDPR
     * Article 22 is about.
     */
    private const OPEN = ['assigned', 'in_progress', 'compliant', 'overdue', 'expired', 'future_allocation'];

    public function __construct(
        private readonly ComplianceStore $store,
        private readonly ClockPort $clock,
        private readonly ComplianceRuleRegistry $rules,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $now = $this->clock->now();
        $rows = $this->store->openAssignments(self::OPEN, self::BATCH);

        $checked = 0;
        $changed = 0;
        $untracked = 0;
        $windowsOpened = 0;
        $compliantClaimed = 0;
        $refused = 0;
        $statusRefused = 0;
        $renewalRefused = 0;
        $completions = 0;

        foreach ($rows as $row) {
            $checked++;
            $requirement = $this->store->requirement((int) $row['requirement_id']);
            $ruleKey = \is_string($requirement['rule_key'] ?? null) ? $requirement['rule_key'] : null;
            // The completion half of the walk, which nothing did. ComplianceState
            // says completed_at is "written by the completion listener" and no
            // listener existed, so the column was null on every row that ever
            // existed and the compliant branch of every rule was unreachable on
            // a running site. Here it is, in its polling form: one read per open
            // assignment whose requirement names a course, and never a write
            // that invents a completion the enrolment does not carry.
            $discovered = $this->discoverCompletion($row, $requirement);
            $completedAt = $this->instant($row['completed_at']) ?? $this->instant($discovered);
            $state = $this->rules->evaluateThrough(
                $ruleKey,
                ComplianceSubject::of(
                    (int) $row['organisation_id'],
                    (int) $row['requirement_id'],
                    (int) $row['user_id'],
                    $this->instant($row['assigned_at']) ?? $now,
                    (int) $row['cycle'],
                    $completedAt,
                    $this->ruleState($row),
                    (int) ($row['rule_state_version'] ?? 1),
                    // Where the renewal axis is standing, so a rule can answer
                    // the next word rather than the final one. Without it a
                    // rule had no way to know it was about to be refused.
                    RenewalStatus::of($row['renewal_status'] ?? null),
                ),
                // No anchor, because a re-evaluation reasons about the dates the
                // assignment already carries. An assignment that needs an anchor
                // never got one at assignment time and is an exception row
                // rather than a row this walk can rescue.
                ComplianceContext::create($now, $this->params($requirement)),
            );

            if ($ruleKey === null || $ruleKey === ComplianceRuleRegistry::NONE || !$this->rules->has($ruleKey)) {
                $untracked++;
            }

            if ($state->renewalStatus === RenewalStatus::WINDOW_OPEN) {
                $windowsOpened++;
            }

            if (ComplianceStatus::isCompliant($state->status)) {
                $compliantClaimed++;
            }

            $outcome = $this->write((int) $row['id'], $row, $state, $discovered);

            if ($discovered !== null) {
                $completions++;
            }

            if ($outcome === self::WRITTEN) {
                $changed++;
            } elseif ($outcome === self::REFUSED) {
                $refused++;
            } elseif ($outcome === self::STATUS_REFUSED) {
                $changed++;
                $statusRefused++;
            } elseif ($outcome === self::RENEWAL_REFUSED) {
                $changed++;
                $renewalRefused++;
            }
        }

        return sprintf(
            'checked=%d changed=%d untracked=%d windows_open=%d compliant_claimed=%d refused=%d'
            . ' status_refused=%d renewal_refused=%d completions=%d',
            $checked,
            $changed,
            $untracked,
            $windowsOpened,
            // Counted from the states this sweep actually saw rather than written
            // as a literal zero. On a free build with no rule registered it is
            // still zero, and it is zero because nothing claimed compliance
            // rather than because the format string said so.
            $compliantClaimed,
            $refused,
            $statusRefused,
            $renewalRefused,
            $completions,
        );
    }

    public function binCount(): int
    {
        return 1;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return self::KEY . ':' . $periodBucket;
    }

    /**
     * The completion the enrolment carries and the assignment has not recorded.
     *
     * Null when the assignment already has one, when the requirement names no
     * course, or when nobody has finished: this discovers a completion, it
     * never manufactures one.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $requirement
     */
    private function discoverCompletion(array $row, array $requirement): ?string
    {
        if ($this->instant($row['completed_at']) !== null) {
            return null;
        }

        $courseId = $requirement['course_id'] ?? null;

        if ($courseId === null || (int) $courseId <= 0) {
            return null;
        }

        return $this->store->courseCompletion((int) $row['user_id'], (int) $courseId);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function write(int $id, array $row, ComplianceState $state, ?string $discovered = null): string
    {
        if (
            $discovered === null
            && (string) ($row['compliance_status'] ?? '') === $state->status
            && (string) ($row['renewal_status'] ?? '') === $state->renewalStatus
        ) {
            return self::UNCHANGED;
        }

        // The transition guard, which this write did not have. Both axes carry
        // a published machine and this sweep saved whatever a rule answered, so
        // a registered rule could move an expired assignment back to assigned,
        // or a renewal_overdue back to due, and nothing anywhere said no.
        //
        // **The two axes are applied independently**, and that is the fix for a
        // seam a third-party rule could not use. They used to be applied
        // together: one refused word discarded the whole write, so a rule that
        // computed its window from the expiry, which is the natural shape,
        // answered `window_open` from `not_due`, lost the accepted compliance
        // status with the refused renewal word, and went on losing it on every
        // later run. A rule may now be wrong about one axis without being
        // silenced on the other, and the summary says which axis refused.
        $from = ComplianceStatus::of($row['compliance_status'] ?? null);
        $fromRenewal = RenewalStatus::of($row['renewal_status'] ?? null);
        $statusOk = ComplianceStatus::permits($from, $state->status);
        $renewalOk = RenewalStatus::permits($fromRenewal, $state->renewalStatus);

        if (!$statusOk && !$renewalOk) {
            return self::REFUSED;
        }

        // The organisation travels with the write, because this is the one
        // sweep that writes to an organisation-owned table from outside an
        // organisation scope, and an id alone is a predicate that cannot be
        // wrong in a way anybody would notice.
        $this->store->saveAssignment(
            $id,
            (int) $row['organisation_id'],
            [
                'compliance_status' => $statusOk ? $state->status : $from,
                'renewal_status' => $renewalOk ? $state->renewalStatus : $fromRenewal,
                'rule_state' => json_encode($state->storedRuleState(), JSON_THROW_ON_ERROR),
                'rule_state_version' => $state->ruleStateVersion,
            ]
            + ($discovered === null ? [] : ['completed_at' => $discovered])
            + $state->dateColumns(),
        );

        if (!$statusOk) {
            return self::STATUS_REFUSED;
        }

        return $renewalOk ? self::WRITTEN : self::RENEWAL_REFUSED;
    }

    /**
     * @param array<string, mixed> $requirement
     *
     * @return array<string, mixed>
     */
    private function params(array $requirement): array
    {
        return self::decode($requirement['rule_params'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function ruleState(array $row): array
    {
        // Both reserved keys stripped, so the provider is handed its own memory
        // and never the core's note list back as if it had written it.
        return ComplianceState::ruleBodyOf(self::decode($row['rule_state'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $raw): array
    {
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function instant(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
