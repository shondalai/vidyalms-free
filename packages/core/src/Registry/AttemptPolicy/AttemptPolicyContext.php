<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\AttemptPolicy;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;

/**
 * What an attempt policy may read while it resolves: which attempt this is,
 * the seed everything presentational derives from, the moment the attempt
 * started, and the quiz's whole policy set.
 *
 * A context on a brand-new interface, in the signature from the start, for
 * the reason the design gives: adding it later breaks every registered
 * provider, and that is the failure this whole item exists to avoid. The
 * quiz policy arrives here rather than as a second parameter for exactly the
 * same reason: the flat settings array resolve() also receives cannot carry
 * a datetime, a nested item session control or a review matrix without
 * inventing an encoding, and growing it would have been the signature change
 * the context exists to prevent.
 */
final class AttemptPolicyContext
{
    private function __construct(
        public readonly int $quizId,
        public readonly int $attemptNumber,
        public readonly Seed $seed,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?string $accessibilityProfile,
        public readonly QuizPolicy $quizPolicy,
    ) {
    }

    public static function create(
        int $quizId,
        int $attemptNumber,
        Seed $seed,
        \DateTimeImmutable $startedAt,
        ?string $accessibilityProfile = null,
        ?QuizPolicy $quizPolicy = null,
    ): self {
        return new self(
            $quizId,
            $attemptNumber,
            $seed,
            $startedAt,
            $accessibilityProfile,
            $quizPolicy ?? new QuizPolicy(),
        );
    }
}
