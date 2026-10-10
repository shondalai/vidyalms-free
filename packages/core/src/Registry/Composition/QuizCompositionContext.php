<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Composition;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ShufflePolicy;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;

/**
 * What a composition may read while it decides which questions one attempt
 * is served.
 *
 * A context on a brand-new interface, in the signature from the start, for
 * the reason the design gives for all of them: adding a parameter later
 * breaks every registered provider, and a composition will grow parameters
 * (an exposure-control step, a per-learner form assignment) long before the
 * interface can be changed safely.
 *
 * The seed is the one thing a selection strategy must take rather than
 * invent. Everything presentational derives from it, so a form is
 * reproducible for support, disputes and regrades; a composition that
 * called a random source of its own would produce a set nobody could
 * reconstruct.
 */
final class QuizCompositionContext
{
    private function __construct(
        public readonly int $quizId,
        public readonly int $attemptNumber,
        public readonly int $userId,
        public readonly Seed $seed,
        public readonly \DateTimeImmutable $startedAt,
        public readonly AttemptPolicySet $policy,
        public readonly string $locale,
    ) {
    }

    public static function create(
        int $quizId,
        int $attemptNumber,
        int $userId,
        Seed $seed,
        \DateTimeImmutable $startedAt,
        AttemptPolicySet $policy,
        string $locale = 'en-GB',
    ): self {
        return new self($quizId, $attemptNumber, $userId, $seed, $startedAt, $policy, $locale);
    }

    /**
     * Whether this attempt's questions are shuffled, with the learner's
     * accessibility profile already applied.
     *
     * **Resolved here rather than by each composition.** A composition that
     * read policy->shufflesQuestions() directly saw the quiz setting with no
     * profile term, so installing a paid composition removed the guarantee
     * the free one honoured, on the one axis the design says is never an
     * upsell. There is now no raw flag on this object for a composition to
     * reach for by mistake, and both compositions cannot disagree because
     * neither computes it.
     */
    public function questionShuffle(): ShufflePolicy
    {
        return ShufflePolicy::resolve(
            $this->policy->shufflesQuestions(),
            $this->policy->accessibilityProfile()->suppressesShuffle(),
        );
    }
}
