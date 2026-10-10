<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * What a compliance rule reads besides the person: the requirement's authored
 * parameters, the anchor the schedule counts from, and the clock.
 *
 * The anchor arrives already resolved, as an instant or as null, rather than as
 * a key the rule would look up. That is the whole of why `anchor_unresolved` is
 * an exception kind and not a silent skip: the caller resolves the anchor
 * through the shipped `ScheduleAnchor` registry, and an anchor resolving to null
 * is a fact the assignment records against the person rather than a reason to
 * pass over them quietly.
 *
 * `params` is the requirement's `rule_params`, already unwrapped from its
 * {@see StoredShape} envelope, with its version beside it so a provider that has
 * moved on can upgrade an old row rather than misreading it.
 */
final class ComplianceContext
{
    /**
     * @param array<string, mixed> $params
     */
    private function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly array $params,
        public readonly int $paramsVersion,
        /**
         * Where this requirement's schedule counts from, resolved by the
         * caller, or null when nothing resolved it.
         */
        public readonly ?\DateTimeImmutable $anchor,
        /** Which registered anchor was asked, for the record a rule may want. */
        public readonly ?string $anchorKey,
        /** The IANA zone the anchor's own arithmetic runs in. */
        public readonly string $timezone,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function create(
        \DateTimeImmutable $now,
        array $params = [],
        ?\DateTimeImmutable $anchor = null,
        ?string $anchorKey = null,
        string $timezone = 'UTC',
    ): self {
        return new self(
            $now,
            StoredShape::bodyOf($params),
            StoredShape::versionOf($params) === StoredShape::UNVERSIONED ? 1 : StoredShape::versionOf($params),
            $anchor,
            $anchorKey === '' ? null : $anchorKey,
            $timezone === '' ? 'UTC' : $timezone,
        );
    }

    /** Whether the schedule this rule is asked about has anything to count from. */
    public function hasAnchor(): bool
    {
        return $this->anchor !== null;
    }
}
