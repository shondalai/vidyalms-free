<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;

/**
 * The Quizzes tab of a course: every quiz with its settings, the lesson it is
 * attached to and its published question count. Requires teach on the course.
 */
final class ListQuizzesForCourse
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly QuizViews $views,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @return list<QuizSummaryView>
     */
    public function execute(Principal $principal, int $courseId): array
    {
        $this->access->require($principal, $courseId, Capability::TEACH);

        return $this->views->summariesForCourse($courseId, $this->quizzes->forCourse($courseId));
    }
}
