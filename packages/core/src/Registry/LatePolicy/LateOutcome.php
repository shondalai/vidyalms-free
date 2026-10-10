<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\LatePolicy;

/**
 * The replayable result of applying a late policy: the percentage after the
 * penalty, the policy key that produced it, and whether the key stored on the
 * course was substituted because its package was gone.
 *
 * The substitution is recorded rather than silently applied, because a late
 * outcome that cannot say which policy produced it is not replayable, which
 * is the standard the attempt grade row already meets.
 *
 * The status and the reason are trailing constructor parameters with
 * defaults, added the way NotificationClass was added to Notification: every
 * existing call site keeps compiling and no provider signature changes. They
 * are here rather than folded into the percentage because a blocked
 * submission is not a mark of zero. Zero is something a learner earned and
 * blocked is a submission the policy refused to take, and a gradebook that
 * cannot tell them apart can explain neither.
 */
final class LateOutcome
{
    /**
     * @param array<string, mixed> $parameters the policy's own params, as applied
     */
    public function __construct(
        public readonly float $percentage,
        public readonly string $policyKey,
        public readonly array $parameters = [],
        public readonly ?string $substitutedFor = null,
        public readonly LateStatus $status = LateStatus::OnTime,
        public readonly ?string $reasonKey = null,
    ) {
    }

    public function wasSubstituted(): bool
    {
        return $this->substitutedFor !== null;
    }

    /**
     * The same outcome recorded as having been produced by a substitute
     * provider, which is what the registry miss for this point does: it
     * falls back to the free policy and records the substitution on the
     * grade row rather than failing the grading path.
     */
    public function substitutedFor(string $originalKey): self
    {
        return new self(
            $this->percentage,
            $this->policyKey,
            $this->parameters,
            $originalKey,
            $this->status,
            $this->reasonKey,
        );
    }
}
