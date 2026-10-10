<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * The advisory integrity ledger. Append-only by contract: there is no update
 * and no delete, because a record a reader can edit is not evidence.
 */
interface IntegrityEventRepository
{
    public function add(IntegrityEvent $event): IntegrityEvent;

    /**
     * One page of an attempt's signals, oldest first, which is the order a
     * human reads them in.
     *
     * @return list<IntegrityEvent>
     */
    public function forAttempt(int $attemptId, int $limit, int $offset): array;

    public function countForAttempt(int $attemptId): int;

    /**
     * How many signals of each kind one attempt already holds.
     *
     * The per-attempt and per-kind caps read this: a learner can post
     * signals in a loop for the length of their own attempt, and nothing
     * prunes an append-only table.
     *
     * @return array<string, int>
     */
    public function countsForAttempt(int $attemptId): array;
}
