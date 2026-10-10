<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * The one place correctness legitimately leaves the server: a quiz with every
 * published question's authoring definition, to a caller who may teach the
 * quiz's course and to nobody else. Removed questions are not listed; they
 * stay in the table for the attempts that reference them.
 */
final class GetQuizAuthoring
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly QuizViews $views,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, int $quizId): QuizSummaryView
    {
        $this->access->requireSignedIn($principal);

        $quiz = $this->quizzes->byId($quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $quizId);

        $this->access->require($principal, $quiz->courseId, Capability::TEACH);

        return $this->views->authoring($quiz);
    }
}
