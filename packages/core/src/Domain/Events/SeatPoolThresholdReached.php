<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * A seat pool crossed a threshold somebody has to act on.
 *
 * One event with a named threshold rather than three events, because the
 * three alerts Open edX names (limited allocations remaining, no allocations
 * remaining, a periodic informational) are three readings of one fact: how
 * many seats are left. Three event classes would mean three payload shapes
 * over the same five numbers and three places to fix a rounding rule.
 *
 * **It carries `organisation_id`, and that is the load-bearing field.** The
 * audience these alerts reach is the organisation's own administrators, and a
 * queued notification records no actor at all, so the tenant has to travel
 * with the occurrence or the fan-out has nothing to narrow on. Amendment A16
 * says the fan-out may not invent a system actor to get one; this is the
 * other half of that answer, which is that the use case raising the alert
 * already holds a scope and stamps the organisation it resolved.
 *
 * The payload carries no person and no address. It is about a pool.
 */
final class SeatPoolThresholdReached extends DomainEvent
{
    /** Seats remain, but few enough that somebody should be told. */
    public const LIMITED = 'limited';

    /** No seats remain at all. */
    public const EXHAUSTED = 'exhausted';

    /** The scheduled summary, raised by the reconciliation pass rather than by a seat moving. */
    public const PERIODIC = 'periodic';

    /** @var list<string> */
    public const THRESHOLDS = [self::LIMITED, self::EXHAUSTED, self::PERIODIC];

    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly string $threshold,
        public readonly int $organisationId,
        public readonly int $poolId,
        public readonly int $courseId,
        /** The pool's own name, because two pools funding one course are two things to manage. */
        public readonly string $poolName,
        public readonly int $capacity,
        public readonly int $remaining,
        /**
         * Whether the two derivations of availability agreed when this was
         * raised.
         *
         * False is the alert the design asks the reconciliation task to raise
         * rather than silently correcting, and it rides the periodic
         * occurrence rather than a fourth threshold, because an administrator
         * does not need a separate mail to learn that the number in the other
         * mail cannot be trusted.
         */
        public readonly bool $reconciled = true,
        /**
         * The occurrence this event belongs to, so a scheduled summary raised
         * twice inside one period is one occurrence.
         *
         * Empty for an alert caused by a seat actually moving, which is
         * identified by the pool and the balance it moved to.
         */
        public readonly string $periodBucket = '',
    ) {
        parent::__construct($occurredAt);

        if (!\in_array($threshold, self::THRESHOLDS, true)) {
            throw new \InvalidArgumentException('Unknown seat pool threshold.');
        }
    }

    public function eventName(): string
    {
        return 'vidyalms.seats.threshold_reached';
    }

    public function payload(): array
    {
        return [
            'threshold' => $this->threshold,
            'organisation_id' => $this->organisationId,
            'pool_id' => $this->poolId,
            'pool_name' => $this->poolName,
            'course_id' => $this->courseId,
            'seats_capacity' => $this->capacity,
            'seats_remaining' => $this->remaining,
            'reconciled' => $this->reconciled,
        ];
    }

    /**
     * The occurrence, not the delivery.
     *
     * A pool that exhausts, is topped up and exhausts again is two
     * occurrences, so the remaining count is part of the key; a scheduled
     * summary is one occurrence per period, so the bucket is. Without both, a
     * host whose scheduler fires twice in a period sends twice, which is the
     * defect the sweep trigger rules exist to prevent.
     */
    public function idempotencyKey(): string
    {
        return 'seats.threshold:' . $this->threshold . ':' . $this->poolId . ':'
            . ($this->periodBucket === '' ? (string) $this->remaining : $this->periodBucket);
    }
}
