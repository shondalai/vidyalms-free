<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;

/**
 * The action needs a finer grain than the canonical digest.
 *
 * Absent means the canonical digest is used unchanged. When present the core
 * **appends** the declared string rather than substituting it, so no provider
 * can ever make two subjects collide: the nine canonical components are always
 * in the key, and the discriminator can only ever split a key further.
 *
 * The interface deliberately has no idempotencyKeyFor(). An earlier draft put
 * one on the action contract with no constraint on what a provider might
 * return, so an action returning a constant, or a key omitting the subject id,
 * claimed one idempotency_keys row under the unique (scope, idem_key) and the
 * runner replayed the first outcome verbatim: a fan-out of four hundred
 * enrolments executed one and recorded 399 replays as executed, each with its
 * own action-log row and success = 1. The failure read as a clean run.
 */
interface DeclaresIdempotencyDiscriminator
{
    /**
     * The extra grain, as a short stable string. It is appended to the
     * canonical digest and never replaces it.
     */
    public function idempotencyDiscriminator(AutomationActionContext $context): string;
}
