<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Grading;

/**
 * Four values, not a boolean, and they cannot be reconstructed after the
 * fact: a boolean cannot tell a skipped item from an unseen one or from one
 * answered invalidly, and reporting needs all three distinctions.
 *
 * Stored on attempt_questions.answered_status, which is why the frozen
 * manifest is a prerequisite rather than a nicety.
 */
enum AnsweredStatus: string
{
    /** Never served to this learner: a pool did not draw it, or a screen was never reached. */
    case NotPresented = 'notpresented';

    /** Served and seen, no response stored. */
    case Presented = 'presented';

    /** A response was stored but it fails the interaction's own constraints. */
    case Attempted = 'attempted';

    /** A valid response is stored. */
    case Answered = 'answered';

    public static function fromWire(?string $value): self
    {
        return $value === null ? self::NotPresented : (self::tryFrom($value) ?? self::NotPresented);
    }

    public function hasResponse(): bool
    {
        return $this === self::Attempted || $this === self::Answered;
    }
}
