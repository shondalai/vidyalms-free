<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Grading;

/**
 * Whether a mark is a mark at all.
 *
 * "notscored" is the value that stops a registry miss being reported as a
 * wrong answer: an unresolvable type, an unresolvable scoring policy and a
 * definition version newer than the provider supports all award raw 0 with
 * this status and an explanatory row, which is a different fact from the
 * learner having got it wrong.
 */
enum ScoreStatus: string
{
    case Scored = 'scored';
    case NotScored = 'notscored';

    public static function fromWire(?string $value): self
    {
        return $value === null ? self::Scored : (self::tryFrom($value) ?? self::Scored);
    }
}
