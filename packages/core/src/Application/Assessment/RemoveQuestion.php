<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * Marks a question removed. Never deletes the row: past attempts keep their
 * answer and grade rows pointing at a real question. Removing an already
 * removed question is a no-op.
 */
final class RemoveQuestion
{
    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly QuizRepository $quizzes,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, int $questionId): void
    {
        $this->access->requireSignedIn($principal);

        $question = $this->questions->byId($questionId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Question', $questionId);
        $quiz = $this->quizzes->byId($question->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $question->quizId);

        $this->access->require($principal, $quiz->courseId, Capability::TEACH);

        if ($question->isRemoved()) {
            return;
        }

        $this->questions->save($question->removed());
    }
}
