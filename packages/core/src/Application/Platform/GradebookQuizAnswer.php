<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/**
 * One answer of a submitted attempt, as a grader sees it. The question
 * travels in its learner presentation, never its definition: grading a short
 * answer needs the prompt and what the learner wrote, and correctness
 * belongs to the authoring view.
 */
final class GradebookQuizAnswer
{
    /**
     * @param array<string, mixed> $presentation
     */
    public function __construct(
        public readonly int $attemptId,
        public readonly int $quizId,
        public readonly string $quizTitle,
        public readonly int $attemptNumber,
        public readonly int $questionId,
        public readonly int $questionPosition,
        public readonly string $questionType,
        public readonly float $questionPoints,
        public readonly array $presentation,
        public readonly mixed $answer,
        public readonly \DateTimeImmutable $answeredAt,
        /**
         * The rubric this answer is marked against, resolved for the grader.
         *
         * The whole version rather than an id: a rater needs the descriptors
         * in front of them to choose a level, and a pointer would make the
         * queue a second round trip per row. Null on a site with no rubric
         * provider, which is what makes the grading surface degrade to a
         * points box rather than break.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $rubric = null,
    ) {
    }
}
