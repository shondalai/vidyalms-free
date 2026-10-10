<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Policy;

use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;

/**
 * A policy that also acts the moment a row is written, not only when it is
 * about to send. Default when absent: the policy runs at send alone.
 *
 * Exactly one shipped policy needs it and the reason is in the design: the
 * frequency cap is measured at send against the 24 hours ending at the actual
 * send instant, while **the duplicate window stays at creation, because
 * cancelling a pending row is its entire purpose**. Run in the send-time fold
 * instead, the drain claims rows in run-at order, the oldest row claimed
 * cancels every newer pending row for that pairing, and the learner receives
 * the stale message while the current one is cancelled: the window inverted.
 *
 * It is a capability probed with instanceof rather than a method on the
 * shipped policy interface, so a third-party policy that only vetoes is
 * unaffected.
 */
interface ActsAtCreation
{
    /**
     * Called once, immediately after the per-recipient row is written, with
     * the context's queue id naming that row.
     *
     * It returns nothing: an action at creation cannot deny (there is nothing
     * to deny yet) and cannot defer (the run-at is the schedule's business).
     */
    public function onEnqueued(Notification $notification, NotificationDecisionContext $context): void;
}
