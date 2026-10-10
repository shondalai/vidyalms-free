<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityEngine;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityMap;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

final class GetCurriculum
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly ModuleRepository $modules,
        private readonly LessonRepository $lessons,
        private readonly CourseAccess $access,
        /**
         * Optional and defaulted so a kernel built before the restriction
         * engine keeps constructing this use case, and so the structural
         * answer is unchanged when nothing is registered: with no engine
         * every row answers available, which is exactly what a build with no
         * rules stored answers anyway.
         */
        private readonly ?AvailabilityEngine $availability = null,
    ) {
    }

    /**
     * The outline as one learner sees it: publication first, then the
     * restriction engine, with hidden rows removed rather than flagged.
     *
     * Availability is evaluated here, on read, and never materialised. A
     * blocked module answers for every lesson inside it, so an author never
     * copies a condition onto forty children and a learner never sees a child
     * reason for a row the parent kept them from reaching.
     *
     * @param array<string, mixed>|null $enrolment
     */
    public function forLearner(Principal $principal, int $courseId, ?array $enrolment = null): CurriculumView
    {
        $sections = $this->execute($principal, $courseId);

        if ($this->availability === null || $sections === []) {
            return new CurriculumView($sections, AvailabilityMap::open());
        }

        $lessonIds = [];

        foreach ($sections as $section) {
            foreach ($section->lessons as $lesson) {
                $lessonIds[] = $lesson->id;
            }
        }

        // The hierarchy comes from the engine rather than from these rows,
        // because the sequencing flags live in three columns and one reader
        // of them is one answer: a second walk here would be a second answer
        // that could drift from the one the completion recompute uses.
        $hierarchy = $this->availability->hierarchyFor($courseId, $lessonIds);
        $courseSubject = new GateSubject(GateSubject::TYPE_COURSE, $courseId);

        foreach ($sections as $section) {
            $hierarchy->add(new GateSubject(GateSubject::TYPE_MODULE, $section->module->id), $courseSubject);
        }

        $map = $this->availability->forCourse($principal, $courseId, $hierarchy, $enrolment);

        return new CurriculumView($this->withoutHidden($sections, $map), $map);
    }

    /**
     * @param list<CurriculumSection> $sections
     *
     * @return list<CurriculumSection>
     */
    private function withoutHidden(array $sections, AvailabilityMap $map): array
    {
        $visible = [];

        foreach ($sections as $section) {
            if (!$map->isVisible(new GateSubject(GateSubject::TYPE_MODULE, $section->module->id))) {
                continue;
            }

            $lessons = [];

            foreach ($section->lessons as $lesson) {
                if ($map->isVisible(new GateSubject(GateSubject::TYPE_LESSON, $lesson->id))) {
                    $lessons[] = $lesson;
                }
            }

            $visible[] = new CurriculumSection($section->module, $lessons);
        }

        return $visible;
    }

    /**
     * @return list<CurriculumSection>
     */
    public function execute(Principal $principal, int $courseId): array
    {
        $course = $this->courses->byId($courseId);
        $author = $course !== null && $this->access->mayAuthor($principal, $course->id);

        // The outline follows the same visibility rule as the course page.
        if ($course === null || !$this->access->mayRead($principal, $course)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Course', $courseId);
        }

        $lessonsByModule = [];

        foreach ($this->lessons->byCourse($courseId) as $lesson) {
            // Draft units inside a live course stay invisible to learners
            // and guests; only authors see the full working structure.
            if (!$author && !$lesson->isPublished()) {
                continue;
            }

            $lessonsByModule[$lesson->moduleId][] = $lesson;
        }

        $sections = [];

        foreach ($this->modules->byCourse($courseId) as $module) {
            if (!$author && $module->state !== Module::STATE_PUBLISHED) {
                continue;
            }

            $sections[] = new CurriculumSection($module, $lessonsByModule[$module->id] ?? []);
        }

        return $sections;
    }
}
