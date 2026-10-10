<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Regrades the closed attempts of one quiz.
 *
 * A question named in the request that is not part of the quiz answers 422
 * rather than being ignored, because a silently ignored id is a regrade the
 * instructor believes ran and did not.
 */
final class RegradeQuiz
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly QuestionRepository $questions,
        private readonly AttemptRepository $attempts,
        private readonly AttemptRegrader $regrader,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, int $quizId, RegradeRequest $request): RegradeOutcome
    {
        $this->access->requireSignedIn($principal);

        $quiz = $this->quizzes->byId($quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $quizId);

        $this->access->require($principal, $quiz->courseId, Capability::GRADE);

        $this->requireQuestionsOfQuiz($quizId, $request);

        return $this->regrader->run(
            $this->attempts->closedForQuiz($quizId),
            $quiz,
            $request->withActor($principal->userId),
        );
    }

    private function requireQuestionsOfQuiz(int $quizId, RegradeRequest $request): void
    {
        if ($request->questionIds === []) {
            return;
        }

        $own = [];

        foreach ($this->questions->forQuiz($quizId) as $question) {
            if ($question instanceof Question && $question->id !== null) {
                $own[$question->id] = true;
            }
        }

        foreach ($request->questionIds as $questionId) {
            if (!isset($own[$questionId])) {
                throw ValidationException::forField(
                    'question_ids',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('Question %d is not part of this quiz.', $questionId),
                );
            }
        }
    }
}
