<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Grading;

/**
 * The reportable verdict on one item, stored on attempt_grades.state.
 *
 * Derived from the fraction and the session status rather than supplied, so
 * a provider cannot report "correct" on a zero fraction.
 */
enum GradeState: string
{
    case Correct = 'correct';
    case Partial = 'partial';
    case Incorrect = 'incorrect';
    case PendingHuman = 'pending_human';
    case NotScored = 'notscored';

    public static function fromWire(?string $value): self
    {
        return $value === null ? self::Incorrect : (self::tryFrom($value) ?? self::Incorrect);
    }

    public static function derive(float $fraction, SessionStatus $session, ScoreStatus $score): self
    {
        if ($session->awaitsHuman()) {
            return self::PendingHuman;
        }

        if ($score === ScoreStatus::NotScored) {
            return self::NotScored;
        }

        if ($fraction >= 1.0) {
            return self::Correct;
        }

        return $fraction > 0.0 ? self::Partial : self::Incorrect;
    }
}
