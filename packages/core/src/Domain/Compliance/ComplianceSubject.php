<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Compliance;

/**
 * One person against one requirement, in one cycle.
 *
 * It carries what the caller has already established and nothing a rule could
 * use to widen its own reach: the organisation, the requirement, the person, the
 * cycle number, and the two facts a rule legitimately needs about the person's
 * position, which are when they were assigned and when, if ever, they completed.
 *
 * A rule provider therefore issues no query, exactly as a join rule and a field
 * disclosure policy issue none, which is what stops a third-party rule reading
 * an organisation-scoped table with no predicate.
 */
final class ComplianceSubject
{
    private function __construct(
        public readonly int $organisationId,
        public readonly int $requirementId,
        public readonly int $userId,
        public readonly int $cycle,
        public readonly \DateTimeImmutable $assignedAt,
        public readonly ?\DateTimeImmutable $completedAt,
        /**
         * The rule's own memory from the previous evaluation, already
         * unwrapped from its stored envelope.
         *
         * @var array<string, mixed>
         */
        public readonly array $ruleState,
        public readonly int $ruleStateVersion,
        /**
         * The renewal word the row carries now.
         *
         * **Additive and trailing, and it exists because a rule could not walk
         * the band it is standing in.** The sweep applies
         * {@see RenewalStatus::permits()} to whatever a rule answers, and a
         * rule computing the window from the expiry answers `window_open` from
         * `not_due`, an edge the machine does not draw. Without this a rule
         * could not tell where the row was and so could not answer the next
         * word rather than the final one. Defaulted to `not_due`, which is
         * where every assignment starts.
         */
        public readonly string $renewalStatus = RenewalStatus::NOT_DUE,
    ) {
        if ($organisationId < 1 || $userId < 1) {
            throw new \InvalidArgumentException('A compliance subject names an organisation and a person.');
        }
    }

    /**
     * @param array<string, mixed> $ruleState
     */
    public static function of(
        int $organisationId,
        int $requirementId,
        int $userId,
        \DateTimeImmutable $assignedAt,
        int $cycle = 1,
        ?\DateTimeImmutable $completedAt = null,
        array $ruleState = [],
        int $ruleStateVersion = 1,
        string $renewalStatus = RenewalStatus::NOT_DUE,
    ): self {
        return new self(
            $organisationId,
            $requirementId,
            $userId,
            max(1, $cycle),
            $assignedAt,
            $completedAt,
            $ruleState,
            max(1, $ruleStateVersion),
            RenewalStatus::of($renewalStatus),
        );
    }
}
