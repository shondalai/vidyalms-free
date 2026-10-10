<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Compliance;

/**
 * The nine compliance states, and never a tenth.
 *
 * **This is deliberately not a registry**, which is the opposite decision from
 * `SeatEventReason` and the reason for the difference is stated rather than
 * assumed: every organisation compliance report filters, buckets and counts on
 * this column, and a denominator cannot be computed over an open vocabulary. A
 * rule needing a finer distinction carries it in `compliance_assignments`
 * `rule_state` and renders it as a sub-label under one of these nine. A seat
 * event reason is evidence and losing it is worse than an unknown label; an
 * unknown compliance status is an unanswerable audit question.
 *
 * **`overdue` and `expired` are two different facts and stay apart.** Overdue is
 * never completed by the due date. Expired is completed and then lapsed. A
 * single non-compliant count hides which of the two an auditor is asking about,
 * which is the distinction Totara draws and Moodle does not.
 */
final class ComplianceStatus
{
    /** The person is in the organisation but this requirement does not reach them. */
    public const NOT_REQUIRED = 'not_required';

    /** Assigned and not started. The state every free assignment sits in. */
    public const ASSIGNED = 'assigned';

    public const IN_PROGRESS = 'in_progress';
    public const COMPLIANT = 'compliant';

    /** Never completed by the due date. */
    public const OVERDUE = 'overdue';

    /** Completed once and now lapsed. */
    public const EXPIRED = 'expired';

    /** Excused by a recorded decision rather than by an absence of evidence. */
    public const EXEMPT = 'exempt';

    /** Paused: the clock does not run and the person is not counted late. */
    public const SUSPENDED = 'suspended';

    /** Assigned ahead of a start date that has not arrived. */
    public const FUTURE_ALLOCATION = 'future_allocation';

    /**
     * The published machine, as the design's own transition table states it.
     *
     * It shipped without one, unlike `DomainStatus` and `IssuerStatus`, which
     * both carry EDGES matching the specification exactly. The consequence was
     * that `ComplianceWindowTask` wrote whatever status a rule answered with no
     * transition check at all, so a registered rule could move an expired
     * assignment back to assigned and nothing anywhere said no; and the drift
     * checker could not compare a machine the code did not publish.
     *
     * **`assigned -> compliant` is the tenth edge and it is an amendment to the
     * design's own table**, which published `assigned -> in_progress -> compliant`
     * and nothing shorter. Nothing in the free build or in Pro ever writes
     * `in_progress`: a rule provider issues no query and is handed only when
     * somebody was assigned and when, if ever, they completed, so the first
     * state a completion can be seen from is the state every assignment starts
     * in. Without this edge an on-time completion was refused by the sweep for
     * ever and the row stayed `assigned`, which is the one answer an auditor
     * cannot recover from in the other direction. The guard is the same one the
     * design puts on the two-step path: `completed_at` is not null.
     *
     * Two states have no outgoing edge here and that is the table's own answer:
     * `expired` renews through the renewal axis rather than through this one,
     * and `exempt` and `suspended` are ended by the decision that made them
     * rather than by the sweep.
     *
     * @var array<string, list<string>>
     */
    public const EDGES = [
        self::NOT_REQUIRED => [],
        self::ASSIGNED => [self::IN_PROGRESS, self::COMPLIANT, self::OVERDUE, self::EXEMPT, self::SUSPENDED],
        self::IN_PROGRESS => [self::OVERDUE, self::COMPLIANT],
        self::COMPLIANT => [self::EXPIRED],
        self::OVERDUE => [self::COMPLIANT],
        self::EXPIRED => [],
        self::EXEMPT => [],
        self::SUSPENDED => [],
        self::FUTURE_ALLOCATION => [self::ASSIGNED],
    ];

    private function __construct()
    {
    }

    /**
     * Whether one status may move to another.
     *
     * Staying put is always permitted, because a sweep that re-reports the
     * state it found is not a transition.
     */
    public static function permits(string $from, string $to): bool
    {
        return $from === $to || \in_array($to, self::EDGES[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::NOT_REQUIRED,
            self::ASSIGNED,
            self::IN_PROGRESS,
            self::COMPLIANT,
            self::OVERDUE,
            self::EXPIRED,
            self::EXEMPT,
            self::SUSPENDED,
            self::FUTURE_ALLOCATION,
        ];
    }

    /**
     * A stored or provider-supplied word, narrowed to the vocabulary.
     *
     * Anything unrecognised reads as {@see ASSIGNED} and never as
     * {@see COMPLIANT}. That is the registry miss direction of the whole
     * compliance seam applied to a single value: the dangerous reading of an
     * unknown compliance answer is the reassuring one, so an unknown answer is
     * assigned and untracked, which is exactly what the free build reports for
     * every assignment it holds.
     */
    public static function of(?string $status): string
    {
        return $status !== null && \in_array($status, self::all(), true) ? $status : self::ASSIGNED;
    }

    /** Whether this state may be reported to an auditor as meeting the requirement. */
    public static function isCompliant(?string $status): bool
    {
        return $status === self::COMPLIANT;
    }
}
