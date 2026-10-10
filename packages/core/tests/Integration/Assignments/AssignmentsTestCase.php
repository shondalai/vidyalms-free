<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assignments;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Assignments\ConfigureAssignment;
use Shondalai\VidyaLMS\Core\Application\Assignments\GetMySubmissions;
use Shondalai\VidyaLMS\Core\Application\Assignments\GradeSubmission;
use Shondalai\VidyaLMS\Core\Application\Assignments\ListSubmissionsForAssignment;
use Shondalai\VidyaLMS\Core\Application\Assignments\SubmitAssignment;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEnrolmentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assignments\DatabaseAssignmentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assignments\DatabaseSubmissionRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseCourseRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

abstract class AssignmentsTestCase extends TestCase
{
    protected TestPorts $env;
    protected AccessGuard $guard;
    protected CourseAccess $access;

    /** @var list<int> */
    private array $staff = [];

    protected DatabaseCourseRepository $courses;
    protected EventBus $events;
    protected DatabaseAssignmentRepository $assignments;
    protected DatabaseSubmissionRepository $submissions;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->guard = new AccessGuard($this->env->authorization);
        $this->courses = new DatabaseCourseRepository($this->env->database, $this->env->clock);
        $this->access = new CourseAccess(
            $this->guard,
            $this->courses,
            new DatabaseEnrolmentRepository($this->env->database, $this->env->clock),
        );
        $this->events = new EventBus($this->env->eventBridge, new NullLogger());
        $this->assignments = new DatabaseAssignmentRepository($this->env->database, $this->env->clock);
        $this->submissions = new DatabaseSubmissionRepository($this->env->database, $this->env->clock);
    }

    protected function configureAssignment(): ConfigureAssignment
    {
        return new ConfigureAssignment($this->assignments, $this->access, $this->env->database);
    }

    protected function submitAssignment(): SubmitAssignment
    {
        return new SubmitAssignment(
            $this->assignments,
            $this->submissions,
            $this->guard,
            $this->events,
            $this->env->database,
            $this->env->clock,
            storage: $this->env->storage,
        );
    }

    protected function gradeSubmission(): GradeSubmission
    {
        return new GradeSubmission(
            $this->submissions,
            $this->assignments,
            $this->access,
            $this->events,
            $this->env->database,
            $this->env->clock,
        );
    }

    protected function getMySubmissions(): GetMySubmissions
    {
        return new GetMySubmissions($this->submissions, $this->guard);
    }

    protected function listSubmissionsForAssignment(): ListSubmissionsForAssignment
    {
        return new ListSubmissionsForAssignment(
            $this->submissions,
            $this->assignments,
            $this->env->database,
            $this->access,
        );
    }

    /**
     * Grants a capability and makes the holder an instructor of the fixture's
     * courses: the per-course rule gives a non-manager only the courses they
     * are assigned to, so a capability alone is not staff.
     */
    protected function grantStaff(int $userId, string $capability): void
    {
        $this->env->authorization->grant($userId, $capability);

        if (\in_array($userId, $this->staff, true)) {
            return;
        }

        $this->staff[] = $userId;

        foreach ($this->env->database->selectAll(Query::from('courses')->select(['id'])) as $row) {
            $this->courses->assignInstructor((int) $row['id'], $userId, 'lead');
        }
    }
    protected function now(): string
    {
        return $this->env->clock->now()->format('Y-m-d H:i:s');
    }

    protected function seedCourse(): int
    {
        $courseId = $this->env->database->insert('courses', [
            'title' => 'Course',
            'state' => 'published',
            'slug' => 'course-' . uniqid(),
            'created_by' => 1,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        foreach ($this->staff as $userId) {
            $this->courses->assignInstructor($courseId, $userId, 'lead');
        }

        return $courseId;
    }

    protected function seedLesson(int $courseId, string $type = 'assignment'): int
    {
        $moduleId = $this->env->database->insert('modules', [
            'course_id' => $courseId, 'title' => 'Module', 'position' => 1, 'state' => 'published',
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        return $this->env->database->insert('lessons', [
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'state' => 'published',
            'title' => 'Lesson',
            'slug' => 'lesson-' . uniqid(),
            'type' => $type,
            'position' => 1,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    protected function seedEnrolment(int $userId, int $courseId, string $status = 'active'): int
    {
        return $this->env->database->insert('enrolments', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'entitlement_id' => 1,
            'status' => $status,
            'enrolled_at' => $this->now(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function seedAssignment(int $lessonId, array $overrides = []): int
    {
        return $this->env->database->insert('assignments', array_merge([
            'lesson_id' => $lessonId,
            'max_points' => 100,
            'max_files' => 1,
            'allow_resubmission' => 0,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], $overrides));
    }

    /**
     * @param class-string<DomainEvent> $eventClass
     *
     * @return list<DomainEvent>
     */
    protected function publishedEventsOf(string $eventClass): array
    {
        return array_values(array_filter(
            $this->env->eventBridge->published,
            static fn (DomainEvent $event): bool => $event instanceof $eventClass,
        ));
    }
}
