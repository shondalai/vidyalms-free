<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\SeatEventReason;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Registry\ProviderEdition;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Why a seat moved, as a registered key rather than a free string.
 *
 * It is seeded from the commerce revocation reasons rather than starting a
 * second reason vocabulary beside them.
 *
 * Miss direction: normal path: the raw key is stored and rendered
 * unlabelled, because a reason is evidence and losing it is worse than losing
 * its label.
 *
 * **The shape is settled.** The slice that stopped storing a free string has
 * landed and eleven free providers ship against this interface, so the label
 * and the grouping are both here and this interface may never gain another
 * method. A policy that needs more declares a capability interface beside it
 * and the reader probes it with instanceof, which is the rule every other
 * shipped seam follows.
 *
 * There is deliberately no edition() member. {@see ProviderEdition} answers
 * that question from the package that registered the key, and its own
 * docblock gives the reason a member would be wrong: it would let a third
 * party claim any edition it liked and would put a commercial fact on a
 * technical interface.
 */
interface SeatEventReasonInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * Which question this reason answers, so a ledger read can group on
     * something narrower than the whole vocabulary.
     *
     * One of {@see SeatEventReason::GROUPS_LIST}. A provider answering
     * anything else is read as `lifecycle`, which is the widest of the three,
     * because a reason with an unreadable group is still evidence and the
     * reader never refuses one.
     */
    public function group(): string;
}
