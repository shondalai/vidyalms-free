<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Grading;

/**
 * QTI's SessionStatusEnum: the runtime state of one item session, which is
 * a different fact from what the author declared (scoring_intent) and from
 * whether the mark counts (ScoreStatus).
 *
 * The old boolean requiresManualGrading fused all three. Splitting them is
 * what lets every human-scored type share one provider path instead of each
 * hardcoding a boolean inside grade().
 */
enum SessionStatus: string
{
    case Initial = 'initial';
    case PendingSubmission = 'pending_submission';
    case PendingResponseProcessing = 'pending_response_processing';
    case PendingExternalScoring = 'pending_external_scoring';
    case Final = 'final';

    public static function fromWire(?string $value): self
    {
        return $value === null ? self::Final : (self::tryFrom($value) ?? self::Final);
    }

    public function awaitsHuman(): bool
    {
        return $this === self::PendingExternalScoring;
    }
}
