<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;

/**
 * The frozen served set of an attempt. Written once at StartAttempt and read
 * by everything downstream, so grading, review, the grading queue and a
 * regrade all see the same list in the same order.
 */
interface AttemptQuestionRepository
{
    /**
     * @param list<AttemptQuestion> $questions
     */
    public function freeze(int $attemptId, array $questions): void;

    /**
     * @return list<AttemptQuestion> ordered by position
     */
    public function forAttempt(int $attemptId): array;

    /**
     * Records what happened to one served question. The answered status is
     * the one fact about a served question that changes during the attempt.
     */
    public function recordAnsweredStatus(int $attemptId, int $questionId, AnsweredStatus $status): void;
}
