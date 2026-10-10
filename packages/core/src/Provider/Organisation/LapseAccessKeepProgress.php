<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatDecisionContext;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatExpiryOutcome;
use Shondalai\VidyaLMS\Core\Registry\SeatExpiryDisposition\SeatExpiryDispositionInterface;

/**
 * The free expiry disposition: access lapses, the record is kept.
 *
 * Expiry expires the derived entitlement rather than deleting it, so the
 * suspension path that already exists applies unchanged and the learner's
 * progress, attempts, grades and any issued certificate survive the term end.
 * IOMAD's "Clear on expire" is the other stance and it is Business, which is
 * why this provider says what it does rather than reading a setting.
 */
final class LapseAccessKeepProgress implements SeatExpiryDispositionInterface
{
    public const KEY = 'lapse_access_keep_progress';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'org.seat_expiry.lapse_access_keep_progress';
    }

    public function disposition(SeatDecisionContext $context): SeatExpiryOutcome
    {
        return SeatExpiryOutcome::lapseAccessKeepProgress();
    }
}
