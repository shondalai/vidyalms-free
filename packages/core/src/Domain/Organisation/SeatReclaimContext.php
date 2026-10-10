<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * What a registered seat reclaim policy decides against.
 *
 * A context object with a private constructor and a named factory, like the
 * thirty-four under Registry\Context and like OrgScope beside it. It was the
 * one third-party-facing context in the area with a public constructor, which
 * meant any caller could fabricate a reclaim decision's inputs, including the
 * two derived booleans the whole policy turns on, and a test could assert
 * against a shape production never produces.
 *
 * The factory takes what the seat lifecycle actually has at the moment it
 * asks, so a provider author reading this class sees one supported way to
 * build one.
 */
final class SeatReclaimContext
{
    /** @param array<string, mixed> $pool
     * @param array<string, mixed> $assignment
     */
    private function __construct(
        public readonly array $pool,
        public readonly array $assignment,
        public readonly bool $started,
        public readonly bool $completed,
        public readonly int $actorId,
        public readonly \DateTimeImmutable $now,
        /**
         * Why the seat is being taken back, from the registered vocabulary.
         *
         * The design names it as a field of every seat decision context and
         * it was the one field missing here, which meant a policy could not
         * distinguish a member leaving from an administrator withdrawing a
         * seat from a pool term ending. It carries the caller's own word when
         * no published key covers it, on the same terms as the ledger.
         */
        public readonly string $reasonKey = SeatEventReason::RECLAIMED_BY_ADMIN,
    ) {
    }

    /**
     * The decision as the seat lifecycle now poses it, read from the
     * assignment's own state.
     *
     * This is the factory that closes the seat aggregate. Its predecessor
     * took two booleans the lifecycle derived by selecting the learner's
     * enrolment row and reading status, progress_percent and
     * last_activity_at, which is the one query the design forbids the reclaim
     * decision to make: it made the seat model a second reader of the
     * progress schema, so a change to how progress is stored silently changed
     * who could take a seat back.
     *
     * consumed and completed are now written onto the assignment by a
     * listener on the existing domain events, so `started` and `completed`
     * are properties of the row the policy is deciding about.
     *
     * @param array<string, mixed> $pool
     * @param array<string, mixed> $assignment
     */
    public static function forAssignment(
        array $pool,
        array $assignment,
        int $actorId,
        \DateTimeImmutable $now,
        string $reasonKey = SeatEventReason::RECLAIMED_BY_ADMIN,
    ): self {
        $state = (string) ($assignment['state'] ?? '');

        return new self(
            $pool,
            $assignment,
            SeatAssignmentState::started($state),
            SeatAssignmentState::completed($state),
            $actorId,
            $now,
            $reasonKey,
        );
    }

    /**
     * The decision posed with the two facts stated rather than read.
     *
     * Kept for the callers that genuinely know them independently of the
     * assignment row, which today is the test harness and tomorrow is an
     * importer replaying a roster that carries its own completion flags.
     * **Every production path uses {@see forAssignment()} instead**, because
     * the assignment's own state is where started and completed now live and
     * a second opinion about them is a second answer.
     *
     * @param array<string, mixed> $pool
     * @param array<string, mixed> $assignment
     */
    public static function forRelease(
        array $pool,
        array $assignment,
        bool $started,
        bool $completed,
        int $actorId,
        \DateTimeImmutable $now,
        string $reasonKey = SeatEventReason::RECLAIMED_BY_ADMIN,
    ): self {
        return new self($pool, $assignment, $started, $completed, $actorId, $now, $reasonKey);
    }
}
