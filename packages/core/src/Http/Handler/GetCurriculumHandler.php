<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Access\ListMyEnrolments;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCurriculum;
use Shondalai\VidyaLMS\Core\Application\Progress\GetMyCourseProgress;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /courses/{id}/curriculum
 *
 * The published structure of a course. When the caller holds an enrolment in
 * it, their per-lesson progress is merged onto each lesson and the enrolment
 * summary rides along; guests and non-enrolled readers get null progress.
 */
final class GetCurriculumHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetCurriculum $getCurriculum,
        private readonly ListMyEnrolments $listMyEnrolments,
        private readonly GetMyCourseProgress $getMyCourseProgress,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');
        $enrolment = $this->enrolmentFor($request, $courseId);
        $view = $this->getCurriculum->forLearner($request->principal, $courseId, $enrolment);
        $sections = $view->sections;
        $progressByLesson = [];

        if ($enrolment !== null) {
            $mine = $this->getMyCourseProgress->execute($request->principal, (int) $enrolment['id']);

            foreach ($mine->lessons as $lessonProgress) {
                $progressByLesson[$lessonProgress->lessonId] = $lessonProgress;
            }
        }

        $modules = [];

        foreach ($sections as $section) {
            $lessons = [];

            foreach ($section->lessons as $lesson) {
                $progress = $progressByLesson[$lesson->id] ?? null;
                $lessons[] = Presenter::lesson($lesson) + [
                    'progress' => $progress instanceof LessonProgress ? Presenter::lessonProgress($progress) : null,
                    // Always present, whatever is installed. With no rules
                    // stored it is the open literal, so the shape ships
                    // before the feature and adding a restriction is never a
                    // breaking contract change.
                    'access' => $view->access
                        ->for(new GateSubject(GateSubject::TYPE_LESSON, $lesson->id))
                        ->outcome
                        ->toLearnerArray(),
                ];
            }

            $modules[] = Presenter::module($section->module) + [
                'lessons' => $lessons,
                'access' => $view->access
                    ->for(new GateSubject(GateSubject::TYPE_MODULE, $section->module->id))
                    ->outcome
                    ->toLearnerArray(),
            ];
        }

        return ApiResponse::ok([
            'course_id' => $courseId,
            'modules' => $modules,
            'enrolment' => $enrolment === null ? null : [
                'id' => (int) $enrolment['id'],
                'status' => $enrolment['status'],
                'progress_percent' => $enrolment['progress_percent'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function enrolmentFor(ApiRequest $request, int $courseId): ?array
    {
        if ($request->principal->isGuest()) {
            return null;
        }

        return $this->listMyEnrolments->forCourse($request->principal, $courseId);
    }
}
