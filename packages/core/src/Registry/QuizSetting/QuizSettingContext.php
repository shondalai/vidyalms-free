<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuizSetting;

use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;

/**
 * What a contributed settings group is told about the quiz it is describing.
 *
 * A context object rather than a parameter list, for the reason ADR 0011
 * gives for every provider method: a context is the only signature shape
 * that can grow without breaking every implementation already shipped. A
 * group that needs the pass mark or the course tomorrow reads a new member
 * rather than forcing a new parameter on a third party's class.
 */
final class QuizSettingContext
{
    private function __construct(
        public readonly int $quizId,
        public readonly int $courseId,
        public readonly QuizPolicy $policy,
    ) {
    }

    public static function create(int $quizId, int $courseId, QuizPolicy $policy): self
    {
        return new self($quizId, $courseId, $policy);
    }
}
