<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\RegradePolicy;

/**
 * What a regrade was asked to do.
 *
 * The reason travels with the appended grade rows, so the audit says why a
 * mark moved rather than only that it did. A dry run computes the same
 * answer, writes nothing, and is what an instructor is shown before a
 * regrade touches a cohort.
 */
final class RegradeRequest
{
    /** @var list<int> */
    public readonly array $questionIds;

    /**
     * @param list<int> $questionIds empty means every question of the quiz
     */
    public function __construct(
        public readonly RegradePolicy $policy,
        array $questionIds = [],
        public readonly bool $dryRun = false,
        public readonly ?string $reason = null,
        /**
         * Who authorised the regrade.
         *
         * The append-only ledger proved what was awarded, under which rule
         * and why, and could not answer who, on the one operation in the
         * system that changes marks a cohort has already seen. The use case
         * checks GRADE on the course before it starts; carrying the actor
         * down is what puts that person on every row it appends.
         */
        public readonly ?int $actorId = null,
    ) {
        $this->questionIds = array_values(array_unique($questionIds));
    }

    /**
     * The request with the caller the use case authorised bound to it.
     *
     * Bound in the use case rather than read off the wire, because the wire
     * cannot be trusted to say who is acting and the use case has already
     * required GRADE on the course from exactly this person.
     */
    public function withActor(?int $userId): self
    {
        return new self($this->policy, $this->questionIds, $this->dryRun, $this->reason, $userId);
    }

    public function covers(int $questionId): bool
    {
        return $this->questionIds === [] || \in_array($questionId, $this->questionIds, true);
    }
}
