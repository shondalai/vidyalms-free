<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * The same operation over a single attempt, which is what an appeal
 * produces.
 *
 * An attempt still in progress answers 409: there is no frozen result to
 * re-run yet, and grading one halfway would write a mark for answers the
 * learner has not finished giving.
 */
final class RegradeAttempt
{
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly QuizRepository $quizzes,
        private readonly AttemptRegrader $regrader,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, int $attemptId, RegradeRequest $request): RegradeOutcome
    {
        $this->access->requireSignedIn($principal);

        $attempt = $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);

        $quiz = $this->quizzes->byId($attempt->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $attempt->quizId);

        $this->access->require($principal, $quiz->courseId, Capability::GRADE);

        if (!$attempt->countsTowardsGrade()) {
            throw new ConflictException('Only a closed attempt can be regraded.');
        }

        return $this->regrader->run([$attempt], $quiz, $request->withActor($principal->userId));
    }
}
