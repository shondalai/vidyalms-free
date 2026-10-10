<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\AttemptPolicy;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyContext;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;

/**
 * Nothing is marked automatically: every item waits for a human, and the
 * attempt stays submitted with a null pass flag until every one has a grade
 * row.
 *
 * The second free behaviour, and it is free rather than Pro because it is
 * what a quiz made entirely of human-marked items already does. Naming it
 * makes the state explicit on the attempt row instead of leaving it implied
 * by the item mix.
 */
final class ManualAttemptPolicy implements AttemptPolicyInterface
{
    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return AttemptPolicyRegistry::MANUAL;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.attempt_policy.manual');
    }

    public function resolve(array $quizSettings, AttemptPolicyContext $context): AttemptPolicySet
    {
        // Nothing is marked automatically, so there is no automatic mark for
        // a negative rule or a try penalty to act on. Both are pinned off
        // rather than carried through, because a stored setting that cannot
        // apply reads as one that did.
        return AttemptPolicySet::fromQuizPolicy($this->key(), $context->quizPolicy, [
            AttemptPolicySet::KEY_SHOW_FEEDBACK => false,
            AttemptPolicySet::KEY_NEGATIVE_MARKING => false,
            AttemptPolicySet::KEY_NEGATIVE_MARKING_POLICY => null,
            AttemptPolicySet::KEY_TRY_PENALTY => 0.0,
        ]);
    }

    public function revealsOutcomeDuringAttempt(): bool
    {
        return false;
    }

    public function triesPerPart(): int
    {
        return 1;
    }
}
