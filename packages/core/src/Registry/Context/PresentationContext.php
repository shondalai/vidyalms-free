<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ChoiceSequence;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;

/**
 * Everything presentForLearner() may need that is not the definition.
 *
 * A value object, so a later field is additive and no registered provider
 * breaks. That property is the entire reason contexts exist: adding a
 * parameter to a shipped interface breaks every third-party implementation,
 * and this design invites third-party types.
 */
final class PresentationContext
{
    public const REVIEW_NONE = 'none';
    public const REVIEW_AFTER_SUBMIT = 'after_submit';
    public const REVIEW_SOLUTION = 'solution';

    private function __construct(
        public readonly Seed $seed,
        public readonly ChoiceSequence $choiceSequence,
        public readonly string $locale,
        public readonly ?string $accessibilityProfile,
        public readonly string $reviewStatus,
    ) {
    }

    public static function create(
        Seed $seed,
        ChoiceSequence $choiceSequence = new ChoiceSequence(),
        string $locale = 'en-GB',
        ?string $accessibilityProfile = null,
        string $reviewStatus = self::REVIEW_NONE,
    ): self {
        return new self($seed, $choiceSequence, $locale, $accessibilityProfile, $reviewStatus);
    }

    /**
     * The context a caller with no attempt in hand uses: an authoring
     * preview, or a gradebook row rendered outside an attempt.
     */
    public static function unseeded(string $locale = 'en-GB'): self
    {
        return new self(Seed::of(''), new ChoiceSequence(), $locale, null, self::REVIEW_NONE);
    }

    public function withChoiceSequence(ChoiceSequence $sequence): self
    {
        return new self($this->seed, $sequence, $this->locale, $this->accessibilityProfile, $this->reviewStatus);
    }

    public function withReviewStatus(string $status): self
    {
        return new self($this->seed, $this->choiceSequence, $this->locale, $this->accessibilityProfile, $status);
    }

    public function isReview(): bool
    {
        return $this->reviewStatus !== self::REVIEW_NONE;
    }
}
