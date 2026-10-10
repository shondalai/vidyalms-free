<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

/**
 * An explicit, auditable override of the tree for one learner.
 *
 * Evaluating on read from current facts means a downward regrade retracts
 * access. That is correct and surprising, and this is the remedy: a grant is
 * visible in the blocked-learners report, attributable, expirable and
 * revocable, and it is evaluated after the tree, so it is always the last
 * word and always recorded as such. Nothing in this design latches.
 *
 * The outcome mapping is explicit, and the third line has a consequence
 * stated on the confirmation dialog rather than discovered later: an
 * open-ended denial removes the item from that learner's completion
 * denominator, which is what an administrator excusing a learner from an item
 * means, and a denial that should not excuse them is a denial with a date on
 * it. The restriction that only a learner-filtering provider may return never
 * does not apply, because a grant is not a provider and an administrative act
 * is the literal definition of never.
 */
final class AvailabilityGrant
{
    public const MODE_GRANT = 'grant';
    public const MODE_DENY = 'deny';

    public const SCOPE_COHORT = 'cohort';
    public const SCOPE_GROUP = 'group';
    public const SCOPE_COURSE = 'course';
    public const SCOPE_SITE = 'site';

    public function __construct(
        public readonly ?int $id,
        public readonly string $scopeType,
        public readonly int $scopeId,
        public readonly GateSubject $subject,
        public readonly int $userId,
        public readonly string $mode,
        public readonly ?string $reason,
        public readonly int $grantedBy,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * Narrowest first. The same specificity direction as the date override
     * merge rule, published in the same place so support reads one order
     * rather than two.
     *
     * @return list<string>
     */
    public static function scopeOrder(): array
    {
        return [self::SCOPE_COHORT, self::SCOPE_GROUP, self::SCOPE_COURSE, self::SCOPE_SITE];
    }

    /**
     * @return list<string>
     */
    public static function modes(): array
    {
        return [self::MODE_GRANT, self::MODE_DENY];
    }

    /** A read-time predicate on the expiry, never swept state. */
    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt === null || $now < $this->expiresAt;
    }

    public function isDeny(): bool
    {
        return $this->mode === self::MODE_DENY;
    }

    public function excludesFromCompletion(): bool
    {
        return $this->isDeny() && $this->expiresAt === null;
    }

    public function outcome(): AvailabilityOutcome
    {
        if (!$this->isDeny()) {
            return AvailabilityOutcome::available();
        }

        if ($this->expiresAt !== null) {
            return AvailabilityOutcome::blocked(
                'availability.grant.denied_until',
                ['until' => $this->expiresAt->format(\DateTimeInterface::ATOM), 'reason' => $this->reason],
                $this->expiresAt,
                null,
            );
        }

        return AvailabilityOutcome::never('availability.grant.denied', ['reason' => $this->reason]);
    }

    /**
     * How specific this row is, lower being narrower, so the resolver picks
     * one answer per subject without re-deriving the order.
     */
    public function specificity(): int
    {
        $position = array_search($this->scopeType, self::scopeOrder(), true);

        return \is_int($position) ? $position : \count(self::scopeOrder());
    }
}
