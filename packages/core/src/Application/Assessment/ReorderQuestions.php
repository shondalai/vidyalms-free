<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Rewrites the positions of a quiz's published questions to 1..n. Order is
 * explicit, never implicit: the list must hold every published question of
 * the quiz exactly once, which is what stops a partial list from silently
 * leaving two questions on the same position. Removed questions keep the
 * position they had, because the attempts that reference them do.
 */
final class ReorderQuestions
{
    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly QuizRepository $quizzes,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @param list<int> $orderedQuestionIds
     */
    public function execute(Principal $principal, int $quizId, array $orderedQuestionIds): void
    {
        $this->access->requireSignedIn($principal);

        $quiz = $this->quizzes->byId($quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $quizId);

        $this->access->require($principal, $quiz->courseId, Capability::TEACH);

        $ordered = array_values($orderedQuestionIds);
        $current = [];

        foreach ($this->questions->publishedForQuiz($quizId) as $question) {
            if ($question->id !== null) {
                $current[] = $question->id;
            }
        }

        if (!$this->isCompletePermutation($ordered, $current)) {
            throw ValidationException::forField(
                'question_ids',
                'The ordered list must contain every published question of the quiz exactly once.',
            );
        }

        $positions = [];

        foreach ($ordered as $index => $questionId) {
            $positions[$questionId] = $index + 1;
        }

        $this->questions->updatePositions($positions);
    }

    /**
     * @param list<int> $ordered
     * @param list<int> $current
     */
    private function isCompletePermutation(array $ordered, array $current): bool
    {
        if (\count($ordered) !== \count($current) || \count($ordered) !== \count(array_unique($ordered))) {
            return false;
        }

        sort($ordered);
        sort($current);

        return $ordered === $current;
    }
}
