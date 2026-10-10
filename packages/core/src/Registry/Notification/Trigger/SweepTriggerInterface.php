<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Registry\Context\SweepContext;

/**
 * A trigger that discovers what is due, because no event exists for the
 * absence of an event.
 *
 * Every occurrence a sweep produces must mint a reference, and that is an
 * invariant of this interface rather than a convention. The dedupe digest
 * works today only because every send has an event behind it; a cron that
 * runs twice would otherwise send twice, which is a defect this design has
 * seen shipped elsewhere. The form is
 * {source}:{key}:{subjectId}:{periodBucket}, and TriggerOccurrence composes
 * it so no provider spells it by hand.
 *
 * The sweep payload carries a bin index and a bin count, so one run does
 * bounded work and cancelByDedupeKey has something meaningful to cancel.
 *
 * @see TriggerOccurrence
 */
interface SweepTriggerInterface extends NotificationTriggerInterface
{
    /**
     * @return iterable<TriggerOccurrence>
     */
    public function dueCandidates(SweepContext $context): iterable;
}
