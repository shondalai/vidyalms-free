<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ChoiceSequence;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * Everything grade() may need that is not the definition and the answer:
 * the frozen learner projection, the order the learner actually saw, the
 * scoring policy registry a type resolves its policy through, and the
 * read-only attempt policy snapshot.
 *
 * The policy registry is here rather than in a type's constructor because a
 * type that took it as a constructor dependency would resolve it at boot,
 * and a policy registered after boot (which is exactly how Pro registers)
 * would then be invisible to it.
 */
final class GradingContext
{
    /**
     * @param array<string, mixed> $presented
     * @param array<string, mixed> $attemptPolicy the frozen snapshot, read only
     */
    private function __construct(
        public readonly ?ScoringPolicyRegistry $policies,
        public readonly array $presented,
        public readonly ChoiceSequence $choiceSequence,
        public readonly array $attemptPolicy,
        public readonly string $locale,
        public readonly int $try,
        public readonly ?int $certainty,
        /**
         * The marking rule the placement overrode the question's own with,
         * frozen on the manifest at attempt start.
         *
         * The design states the resolution order in three places, slot then
         * question then definition, and this is the first of the three. It
         * arrives through the context rather than through the definition
         * because a quiz-level or placement-level decision must never reach
         * a question type as a definition field: a type would then be able
         * to depend on one.
         */
        public readonly ?string $policyOverride = null,
        /** @var array<string, mixed> */
        public readonly array $policyOverrideParams = [],
    ) {
    }

    /**
     * @param array<string, mixed> $presented
     * @param array<string, mixed> $attemptPolicy
     * @param array<string, mixed> $policyOverrideParams
     */
    public static function create(
        ?ScoringPolicyRegistry $policies = null,
        array $presented = [],
        ChoiceSequence $choiceSequence = new ChoiceSequence(),
        array $attemptPolicy = [],
        string $locale = 'en-GB',
        int $try = 1,
        ?int $certainty = null,
        ?string $policyOverride = null,
        array $policyOverrideParams = [],
    ): self {
        return new self(
            $policies,
            $presented,
            $choiceSequence,
            $attemptPolicy,
            $locale,
            $try,
            $certainty,
            $policyOverride,
            $policyOverrideParams,
        );
    }

    /**
     * The context a caller outside an attempt uses: a manual grading probe,
     * or an authoring preview. Nothing frozen is available, so a type falls
     * back to its own definition.
     */
    public static function detached(?ScoringPolicyRegistry $policies = null): self
    {
        return new self($policies, [], new ChoiceSequence(), [], 'en-GB', 1, null);
    }

    public function toScoringContext(): ScoringContext
    {
        $behaviour = $this->attemptPolicy['behaviour'] ?? null;

        return ScoringContext::create(
            behaviour: \is_string($behaviour) ? $behaviour : ScoringContext::BEHAVIOUR_DEFERRED_FEEDBACK,
            try: $this->try,
            certainty: $this->certainty,
            locale: $this->locale,
            attemptPolicy: $this->attemptPolicy,
        );
    }
}
