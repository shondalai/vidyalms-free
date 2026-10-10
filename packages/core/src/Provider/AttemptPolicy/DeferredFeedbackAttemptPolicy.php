<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\AttemptPolicy;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyContext;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;

/**
 * The free build's default behaviour and what every quiz has always done:
 * the learner answers everything, submits, and every item session closes at
 * once.
 *
 * That makes deferred feedback the special case of the correctness invariant
 * where the item session boundary and the attempt boundary coincide, which
 * is the majority case and costs nothing.
 */
final class DeferredFeedbackAttemptPolicy implements AttemptPolicyInterface
{
    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return AttemptPolicyRegistry::DEFERRED_FEEDBACK;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.attempt_policy.deferred_feedback');
    }

    public function resolve(array $quizSettings, AttemptPolicyContext $context): AttemptPolicySet
    {
        // The quiz's whole policy set, with the two members this behaviour
        // owns overridden. No outcome reaches the client before the attempt
        // ends, so no feedback is shown during it whatever the item session
        // control says, and one try per item means a try penalty can never
        // bind.
        return AttemptPolicySet::fromQuizPolicy($this->key(), $context->quizPolicy, [
            AttemptPolicySet::KEY_SHOW_FEEDBACK => false,
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
