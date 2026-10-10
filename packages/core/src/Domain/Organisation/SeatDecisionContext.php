<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * What a registered seat policy decides against.
 *
 * One context shape for the three policy families that answer a question
 * about a pool rather than about a reclaim: scope arity, over-allocation and
 * expiry disposition. The reclaim family keeps its own
 * {@see SeatReclaimContext}, because it is the only one that also carries an
 * assignment's derived progress state and it already ships.
 *
 * Private constructor and named factories, like every context object beside
 * it: a provider author cannot fabricate a pool row, an availability number
 * or a clock, so a policy is a decision rather than a second reader of the
 * seat tables. It carries no database handle and no Principal for the same
 * reason.
 *
 * The parameter bag is the pool's own `scope_params` or `policy_params`
 * already decoded, so a provider reads its own configuration without knowing
 * which column it came out of or which StoredShape version wrote it; the
 * version travels beside it so a provider that has changed shape can tell.
 */
final class SeatDecisionContext
{
    /**
     * @param array<string, mixed> $pool
     * @param array<string, mixed> $params
     */
    private function __construct(
        public readonly array $pool,
        public readonly array $params,
        public readonly int $paramsVersion,
        public readonly ?int $courseId,
        public readonly int $available,
        public readonly int $held,
        public readonly ?int $subjectUserId,
        public readonly string $reasonKey,
        public readonly \DateTimeImmutable $now,
    ) {
    }

    /**
     * The question the assigning use case asks: does this pool's scope cover
     * the course being assigned, and how many seats does one assignment of it
     * spend.
     *
     * @param array<string, mixed> $pool
     * @param array<string, mixed> $params
     */
    public static function forScope(
        array $pool,
        array $params,
        int $paramsVersion,
        ?int $courseId,
        \DateTimeImmutable $now,
    ): self {
        return new self($pool, $params, $paramsVersion, $courseId, 0, 0, null, SeatEventReason::GRANTED_BY_ADMIN, $now);
    }

    /**
     * The question asked when the pool cannot seat somebody: refuse, queue a
     * durable row, or seat them anyway and flag it.
     *
     * available is the authoritative number the ledger and the state count
     * agree on, computed by the caller before the lock is taken, so no
     * provider recomputes availability and no two providers can disagree
     * about it.
     *
     * @param array<string, mixed> $pool
     * @param array<string, mixed> $params
     */
    public static function forOverAllocation(
        array $pool,
        array $params,
        int $paramsVersion,
        ?int $courseId,
        int $available,
        int $held,
        int $subjectUserId,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $pool,
            $params,
            $paramsVersion,
            $courseId,
            $available,
            $held,
            $subjectUserId,
            SeatEventReason::GRANTED_BY_ADMIN,
            $now,
        );
    }

    /**
     * The question the term sweep asks once a pool's term has ended: what
     * happens to a seat that was still held.
     *
     * @param array<string, mixed> $pool
     * @param array<string, mixed> $params
     */
    public static function forExpiry(
        array $pool,
        array $params,
        int $paramsVersion,
        ?int $courseId,
        ?int $subjectUserId,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $pool,
            $params,
            $paramsVersion,
            $courseId,
            0,
            0,
            $subjectUserId,
            SeatEventReason::EXPIRED_WITH_TERM,
            $now,
        );
    }
}
