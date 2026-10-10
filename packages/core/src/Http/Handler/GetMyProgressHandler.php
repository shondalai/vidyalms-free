<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Access\ListMyEnrolments;
use Shondalai\VidyaLMS\Core\Application\Progress\GetMyCourseProgress;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /me/progress?course_id=
 */
final class GetMyProgressHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListMyEnrolments $listMyEnrolments,
        private readonly GetMyCourseProgress $getMyCourseProgress,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->queryInt('course_id', 0);

        if ($courseId < 1) {
            throw ApiProblem::validation(['course_id' => 'A positive course id is required.']);
        }

        $enrolmentId = $this->enrolmentIdFor($request, $courseId);

        if ($enrolmentId === null) {
            throw ApiProblem::notFound('No enrolment in this course.');
        }

        $progress = $this->getMyCourseProgress->execute($request->principal, $enrolmentId);
        $summary = $progress->summary;

        return ApiResponse::ok([
            'enrolment_id' => $progress->enrolmentId,
            'course_id' => $progress->courseId,
            'enrolment_status' => $progress->enrolmentStatus,
            'summary' => $summary === null ? null : [
                'lessons_total' => $summary->lessonsTotal,
                'lessons_completed' => $summary->lessonsCompleted,
                'percent' => $summary->percent,
                'quiz_average' => $summary->quizAverage,
                'final_quiz_passed' => $summary->finalQuizPassed,
                'computed_at' => Presenter::dateTime($summary->computedAt),
            ],
            'lessons' => array_map(
                static fn (LessonProgress $lesson): array => Presenter::lessonProgress($lesson),
                $progress->lessons,
            ),
        ]);
    }

    private function enrolmentIdFor(ApiRequest $request, int $courseId): ?int
    {
        $row = $this->listMyEnrolments->forCourse($request->principal, $courseId);
        return $row === null ? null : (int) $row['id'];
    }
}
