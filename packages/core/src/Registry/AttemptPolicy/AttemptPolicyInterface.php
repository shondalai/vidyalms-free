<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\AttemptPolicy;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One attempt behaviour: how an attempt aggregates, whether feedback appears
 * during it, whether a part can be retried, and what the resolved policy set
 * looks like once frozen.
 *
 * The free build registers deferred_feedback and manual. Pro adds
 * immediate_feedback, interactive_multi_try and certainty_based, and none of
 * them needs a core edit, because the behaviour is resolved through this
 * registry and frozen onto the attempt row rather than branched on.
 */
interface AttemptPolicyInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * Turns the quiz's own settings into the set that is frozen onto the
     * attempt.
     *
     * @param array<string, mixed> $quizSettings
     */
    public function resolve(array $quizSettings, AttemptPolicyContext $context): AttemptPolicySet;

    /**
     * Whether an item's outcome may reach the client before the attempt
     * ends. False for every free behaviour, which is why the correctness
     * invariant costs the free build nothing.
     */
    public function revealsOutcomeDuringAttempt(): bool;

    /**
     * The number of tries a learner gets per part. One for both free
     * behaviours; a multi-try behaviour raises it and the try penalty then
     * applies per part, never per question, which is the bug Moodle shipped
     * and fixed in MDL-36955.
     */
    public function triesPerPart(): int;
}
