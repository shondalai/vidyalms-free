<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * A policy that says which of the twelve kinds its own denial is.
 *
 * `PolicyDecision` is the notification engine's fold, taken unchanged so mail
 * and AI read a deny the same way, and it carries a reason string rather than
 * an AI refusal kind. The stage folding it therefore had one kind to hand and
 * used `ceiling_exceeded` for every denial: a disabled panel, an unaccepted
 * data-use policy, a stopped site and a subject outside the caller's scope
 * were all filed in the budget bucket, so the usage report's refusal counts
 * could not tell a site that was over budget from one nobody had consented on,
 * and the retry decision, which turns on the kind, was wrong for all of them.
 *
 * Widening `PolicyDecision` would fork an object two engines share, and a map
 * of shipped reason strings inside the stage would be a core edit for every
 * vendor gate. So the policy answers for its own reasons, which is the same
 * shape every other declaration in this design takes: a vendor gate publishes
 * its kind and gets counted correctly with no core edit.
 *
 * A policy that declares nothing, or answers null for a reason it does not
 * own, keeps `ceiling_exceeded`, which is what the six shipped ceilings mean
 * literally.
 */
interface DeclaresRefusalKind
{
    /**
     * The kind of a denial this policy raised, or null when the reason is not
     * one of its own.
     */
    public function refusalKindFor(string $reason): ?RefusalKind;
}
