<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ArchiveOutcome;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AuthoringLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AuthoringSection;
use Shondalai\VidyaLMS\Core\Application\Curriculum\Instructor;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ManagedCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ManagedCourseDetail;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Http\Handler\Presenter;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategory;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;

/**
 * The wire shapes of courses, modules and lessons as an author sees them.
 *
 * Authoring content travels verbatim, storage paths included, because the
 * author sends it back unchanged on update. That is a rule of the surface and
 * it lives here, beside the shape it governs.
 */
final class CurriculumPresenter
{
    public const SHAPE_MANAGED_COURSE = 'managed_course';
    public const SHAPE_MANAGED_COURSE_DETAIL = 'managed_course_detail';
    public const SHAPE_MODULE = 'module';
    public const SHAPE_AUTHORING_LESSON = 'authoring_lesson';
    public const SHAPE_AUTHORING_CURRICULUM = 'authoring_curriculum';
    public const SHAPE_INSTRUCTOR = 'instructor';
    public const SHAPE_ARCHIVE_OUTCOME = 'archive_outcome';
    public const SHAPE_LESSON_TYPE = 'lesson_type';
    public const SHAPE_COURSE_CATEGORY = 'course_category';

    public function __construct(
        private readonly Decorated $decorated,
        private readonly SharedPresenter $shared,
        private readonly AssessmentPresenter $assessment,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function instructor(Instructor $instructor): array
    {
        return $this->decorated->apply(self::SHAPE_INSTRUCTOR, [
            'user_id' => $instructor->userId,
            'name' => $instructor->name,
            'role' => $instructor->role,
        ], $instructor);
    }

    /**
     * @return array<string, mixed>
     */
    public function courseCategory(CourseCategory $category): array
    {
        return $this->decorated->apply(self::SHAPE_COURSE_CATEGORY, [
            'id' => $category->id,
            'name' => $category->name,
            'parent_id' => $category->parentId,
            'label' => $category->label,
            'state' => $category->state->value,
            'edit_url' => $category->editUrl,
        ], $category);
    }

    /**
     * @return array<string, mixed>
     */
    public function managedCourse(ManagedCourse $managed): array
    {
        $course = $managed->course;
        $counts = $managed->counts;

        return $this->decorated->apply(self::SHAPE_MANAGED_COURSE, [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug->value,
            'summary' => $course->summary,
            'image' => $course->image,
            'category_id' => $course->categoryId,
            'level' => $course->level,
            'language' => $course->language,
            'visibility' => $course->visibility->value,
            'state' => $course->state->value,
            'price_mode' => $course->priceMode,
            'featured' => $course->featured,
            'completion_rule' => $course->completionRule->value,
            'completion_threshold' => $course->completionThreshold,
            'published_at' => Presenter::dateTime($course->publishedAt),
            'updated_at' => Presenter::dateTime($managed->updatedAt),
            'lesson_count' => $counts->lessons,
            'archived_lesson_count' => $counts->archivedLessons,
            'enrolled_count' => $counts->enrolled,
            'active_count' => $counts->active,
            'completed_count' => $counts->completed,
            'suspended_count' => $counts->suspended,
            'completion_rate' => $counts->completionRate(),
            'instructors' => array_map($this->instructor(...), $managed->instructors),
        ], $managed);
    }

    /**
     * @return array<string, mixed>
     */
    public function managedCourseDetail(ManagedCourseDetail $detail): array
    {
        return $this->decorated->apply(
            self::SHAPE_MANAGED_COURSE_DETAIL,
            $this->managedCourse($detail->managed) + [
                'description' => $detail->managed->course->description,
                'created_by' => $detail->managed->course->createdBy,
                'created_at' => Presenter::dateTime($detail->managed->createdAt),
                'prerequisites' => array_map($this->shared->courseRef(...), $detail->prerequisites),
                'pending_grading_count' => $detail->pendingGradingCount,
                'enrolment_rule' => $detail->managed->course->enrolmentRule,
                'progression' => $detail->managed->course->progression,
                'timezone' => $detail->managed->course->timezone,
                'starts_at' => Presenter::dateTime($detail->managed->course->startsAt),
                'late_policy_key' => $detail->managed->course->latePolicyKey,
                'late_policy_params' => $detail->managed->course->latePolicyParams === null
                    ? null
                    : Presenter::freeFormObject($detail->managed->course->latePolicyParams),
            ],
            $detail,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function moduleRecord(Module $module): array
    {
        return $this->decorated->apply(self::SHAPE_MODULE, [
            'id' => $module->id,
            'course_id' => $module->courseId,
            'title' => $module->title,
            'summary' => $module->summary,
            'position' => $module->position,
            'state' => $module->state,
        ], $module);
    }

    /**
     * @return array<string, mixed>
     */
    public function authoringLesson(AuthoringLesson $authoring): array
    {
        $lesson = $authoring->lesson;

        return $this->decorated->apply(self::SHAPE_AUTHORING_LESSON, [
            'id' => $lesson->id,
            'course_id' => $lesson->courseId,
            'module_id' => $lesson->moduleId,
            'title' => $lesson->title,
            'slug' => $lesson->slug->value,
            'type' => $lesson->type,
            'summary' => $lesson->summary,
            'duration_seconds' => $lesson->durationSeconds,
            'position' => $lesson->position,
            'state' => $lesson->state,
            'free_preview' => $lesson->freePreview,
            'content' => Presenter::freeFormObject($authoring->content),
            'content_available' => $authoring->contentAvailable,
            'quiz' => $authoring->quiz === null ? null : $this->assessment->quizSummary($authoring->quiz),
            'assignment' => $authoring->assignment === null
                ? null
                : $this->assessment->assignment($authoring->assignment),
        ], $authoring);
    }

    /**
     * @param list<AuthoringSection> $sections
     *
     * @return array<string, mixed>
     */
    public function authoringCurriculum(int $courseId, array $sections): array
    {
        return [
            'course_id' => $courseId,
            'modules' => array_map(
                fn (AuthoringSection $section): array => $this->moduleRecord($section->module) + [
                    'lessons' => array_map($this->authoringLesson(...), $section->lessons),
                ],
                $sections,
            ),
        ];
    }

    /**
     * What an archive did, beside the entity it archived. The recompute is
     * named rather than implied, because a certificate arriving twenty
     * seconds after an authoring click is otherwise inexplicable.
     *
     * @return array<string, mixed>
     */
    public function archiveOutcome(ArchiveOutcome $outcome): array
    {
        return $this->decorated->apply(self::SHAPE_ARCHIVE_OUTCOME, [
            'lessons_archived' => $outcome->lessonsArchived,
            'enrolments_affected' => $outcome->enrolmentsAffected,
            'progress_recompute' => $outcome->progressRecompute,
        ], $outcome);
    }

    /**
     * @return array<string, mixed>
     */
    public function lessonType(LessonTypeInterface $type): array
    {
        return $this->decorated->apply(self::SHAPE_LESSON_TYPE, [
            'key' => $type->key(),
            'label' => $type->label(),
            'completion_mode' => $type->completionMode(),
        ], $type);
    }
}
