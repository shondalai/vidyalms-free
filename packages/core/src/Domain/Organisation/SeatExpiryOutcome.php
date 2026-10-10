<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * What a pool's term end does to a seat that was still held.
 *
 * The free answer is the whole of IOMAD's argument settled one way: access
 * lapses and the learning record stays, because progress is the learner's and
 * access is the buyer's. IOMAD's own "Clear on expire" is the other stance
 * and it is Business, so the second flag below exists to be set by a provider
 * this build does not ship rather than to be read from a setting.
 */
final class SeatExpiryOutcome
{
    private function __construct(
        public readonly bool $lapsesAccess,
        public readonly bool $closesMembership,
        public readonly bool $keepsProgress,
    ) {
    }

    /** The free disposition: the entitlement expires, nothing else moves. */
    public static function lapseAccessKeepProgress(): self
    {
        return new self(true, false, true);
    }

    /** The Business disposition, named here so registering it is not a core edit. */
    public static function lapseAccessCloseMembership(): self
    {
        return new self(true, true, true);
    }
}
