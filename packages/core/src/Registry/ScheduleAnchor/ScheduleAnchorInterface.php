<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor;

use Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Where a schedule counts from.
 *
 * A fixed date and a relative offset are the same condition with a different
 * anchor, which is why one small registry covers every surface surveyed. It
 * is shared by availability conditions, the cohort schedule resolver and
 * notification schedules, so there is one anchor vocabulary in the product
 * rather than three.
 *
 * An unresolved anchor makes its condition blocked with no opensAt, never
 * immediately available. That is the one registry miss whose obvious reading
 * is the dangerous one, and it is published in the miss table for that
 * reason.
 */
interface ScheduleAnchorInterface extends ProviderInterface
{
    public function label(): string;

    public function resolve(AnchorContext $context): ?\DateTimeImmutable;

    /**
     * The IANA zone this anchor's arithmetic runs in. "N days" is done in the
     * anchor's local calendar and never by adding N times 86400 seconds, or a
     * DST transition lands day seven an hour early or late.
     */
    public function timezoneFor(AnchorContext $context): string;
}
