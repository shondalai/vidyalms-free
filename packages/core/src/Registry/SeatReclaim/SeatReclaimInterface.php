<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\SeatReclaim;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatReclaimContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Whether a seat may be taken back from the person holding it.
 *
 * What licensing vendors call a floating or reusable seat is always a named
 * seat with a reclaim rule, because a course is consumed over weeks rather
 * than in a session. This is that rule, and the four free ones cover never,
 * always, before the learner started and before they finished.
 *
 * The policy never queries progress. Whether the assignment has started or
 * finished is on the context, decided by the use case, so a provider is a
 * decision and not a second reader of the learning tables.
 *
 * Miss direction: fail closed. The seat cannot be reclaimed, because taking
 * access away from a learner when nobody can say whether the rule allows it is
 * the wrong way to be wrong.
 */
interface SeatReclaimInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    public function allows(SeatReclaimContext $context): bool;
}
