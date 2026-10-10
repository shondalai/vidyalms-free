<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;

/**
 * The authoring view of a whole curriculum: every module and lesson, drafts
 * included, each lesson with its stored content and per-type configuration.
 *
 * Unlike the learner curriculum, nothing is filtered by state: the point of
 * the editor is the working structure. Requires teach on the course.
 */
final class GetAuthoringCurriculum
{
    public function __construct(
        private readonly ModuleRepository $modules,
        private readonly LessonRepository $lessons,
        private readonly AuthoringLessonView $view,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @return list<AuthoringSection>
     */
    public function execute(Principal $principal, int $courseId): array
    {
        $this->access->require($principal, $courseId, Capability::TEACH);

        $lessonsByModule = [];

        foreach ($this->lessons->byCourse($courseId) as $lesson) {
            $lessonsByModule[$lesson->moduleId][] = $lesson;
        }

        $sections = [];

        foreach ($this->modules->byCourse($courseId) as $module) {
            $sections[] = new AuthoringSection(
                $module,
                $this->view->ofMany($lessonsByModule[$module->id] ?? []),
            );
        }

        return $sections;
    }
}
