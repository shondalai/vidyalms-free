<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/**
 * One row of a course gradebook, from either source. The key is stable
 * across pages and refreshes, which is what lets a screen keep its place
 * while the queue drains under it.
 *
 * lessonId is null when the work does not hang off a lesson. A quiz belongs
 * to a course and may be linked to a lesson or not, so an answer of an
 * unlinked quiz has no lesson; the title then names the quiz. It used to
 * travel as 0, which is a lesson id no lesson has and which a client
 * following the contract would have tried to open.
 */
final class GradebookItem
{
    public const TYPE_SUBMISSION = 'submission';
    public const TYPE_QUIZ_ANSWER = 'quiz_answer';

    public const STATUS_PENDING = 'pending';
    public const STATUS_GRADED = 'graded';

    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $status,
        public readonly int $learnerId,
        public readonly ?string $learnerName,
        public readonly int $courseId,
        public readonly ?int $lessonId,
        public readonly string $lessonTitle,
        public readonly \DateTimeImmutable $waitingSince,
        public readonly ?ManualGrade $grade,
        public readonly ?GradebookSubmission $submission,
        public readonly ?GradebookQuizAnswer $quizAnswer,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * The name arrives after the row, because every name in the queue is
     * resolved through the users port in one call once the whole queue is
     * known.
     */
    public function withLearnerName(?string $learnerName): self
    {
        return new self(
            $this->key,
            $this->type,
            $this->status,
            $this->learnerId,
            $learnerName,
            $this->courseId,
            $this->lessonId,
            $this->lessonTitle,
            $this->waitingSince,
            $this->grade,
            $this->submission,
            $this->quizAnswer,
        );
    }
}
