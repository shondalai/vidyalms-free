<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Progress;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Curriculum\LessonAvailability;
use Shondalai\VidyaLMS\Core\Application\Progress\CompleteAssignmentLessonOnGrade;
use Shondalai\VidyaLMS\Core\Application\Progress\CompleteQuizLessonOnAttempt;
use Shondalai\VidyaLMS\Core\Application\Progress\GetMyCourseProgress;
use Shondalai\VidyaLMS\Core\Application\Progress\RecomputeCourseProgress;
use Shondalai\VidyaLMS\Core\Application\Progress\RecordLessonProgress;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Progress\CompletionEngine;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress\SqlCourseProgressRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress\SqlLessonProgressRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress\SqlVideoPositionRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

abstract class ProgressTestCase extends TestCase
{
    protected TestPorts $env;
    protected EventBus $events;
    protected AccessGuard $guard;
    protected LessonTypeRegistry $lessonTypes;
    protected SqlLessonProgressRepository $lessonProgressRepository;
    protected SqlCourseProgressRepository $courseProgressRepository;
    protected SqlVideoPositionRepository $videoPositionRepository;
    protected RecomputeCourseProgress $recomputeCourseProgress;
    protected RecordLessonProgress $recordLessonProgress;
    protected GetMyCourseProgress $getMyCourseProgress;
    protected CompleteQuizLessonOnAttempt $completeQuizLessonOnAttempt;
    protected CompleteAssignmentLessonOnGrade $completeAssignmentLessonOnGrade;

    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->events = new EventBus($this->env->eventBridge, new NullLogger());
        $this->guard = new AccessGuard($this->env->authorization);

        $this->lessonTypes = new LessonTypeRegistry();
        $this->lessonTypes->register(new StubLessonType('video', LessonTypeInterface::COMPLETION_ON_VIEW));
        $this->lessonTypes->register(new StubLessonType('reading', LessonTypeInterface::COMPLETION_ON_VIEW));
        $this->lessonTypes->register(new StubLessonType('quiz', LessonTypeInterface::COMPLETION_ON_PASS));
        $this->lessonTypes->register(new StubLessonType('assignment', LessonTypeInterface::COMPLETION_ON_SUBMISSION));

        $database = $this->env->database;
        $clock = $this->env->clock;

        $this->lessonProgressRepository = new SqlLessonProgressRepository($database, $clock);
        $this->courseProgressRepository = new SqlCourseProgressRepository($database, $clock);
        $this->videoPositionRepository = new SqlVideoPositionRepository($database, $clock);

        $availability = new LessonAvailability($database);

        $this->recomputeCourseProgress = new RecomputeCourseProgress(
            $this->lessonProgressRepository,
            $this->courseProgressRepository,
            new CompletionEngine(),
            $this->guard,
            $this->events,
            $clock,
            $database,
            $availability,
        );

        $this->recordLessonProgress = new RecordLessonProgress(
            $this->lessonProgressRepository,
            $this->videoPositionRepository,
            $this->recomputeCourseProgress,
            $this->lessonTypes,
            $this->guard,
            $this->events,
            $clock,
            $database,
            $availability,
        );

        $this->getMyCourseProgress = new GetMyCourseProgress(
            $this->lessonProgressRepository,
            $this->courseProgressRepository,
            $this->guard,
            $database,
        );

        $this->completeQuizLessonOnAttempt = new CompleteQuizLessonOnAttempt(
            $this->lessonProgressRepository,
            $this->recomputeCourseProgress,
            $this->events,
            $clock,
        );

        $this->completeAssignmentLessonOnGrade = new CompleteAssignmentLessonOnGrade(
            $this->lessonProgressRepository,
            $this->recomputeCourseProgress,
            $this->events,
            $clock,
        );
    }

    protected function seedCourse(
        string $completionRule = 'all_lessons',
        ?int $completionThreshold = null,
        string $state = 'published',
    ): int {
        $n = ++$this->sequence;

        return $this->env->database->insert('courses', [
            'title' => 'Course ' . $n,
            'slug' => 'course-' . $n,
            'visibility' => 'public',
            'state' => $state,
            'price_mode' => 'free',
            'completion_rule' => $completionRule,
            'completion_threshold' => $completionThreshold,
            'featured' => 0,
            'created_by' => 1,
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    protected function seedModule(int $courseId): int
    {
        $n = ++$this->sequence;

        return $this->env->database->insert('modules', [
            'course_id' => $courseId,
            'title' => 'Module ' . $n,
            'position' => $n,
            'state' => 'published',
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    protected function seedLesson(
        int $courseId,
        int $moduleId,
        string $type = 'reading',
        string $state = 'published',
        ?int $durationSeconds = null,
    ): int {
        $n = ++$this->sequence;

        return $this->env->database->insert('lessons', [
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'title' => 'Lesson ' . $n,
            'slug' => 'lesson-' . $n,
            'type' => $type,
            'duration_seconds' => $durationSeconds,
            'position' => $n,
            'state' => $state,
            'free_preview' => 0,
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    protected function seedEnrolment(int $userId, int $courseId, string $status = 'active'): int
    {
        return $this->env->database->insert('enrolments', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'entitlement_id' => ++$this->sequence,
            'status' => $status,
            'enrolled_at' => $this->nowString(),
            'completed_at' => null,
            'last_activity_at' => null,
            'progress_percent' => 0,
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    protected function seedQuiz(int $courseId, ?int $lessonId = null, bool $isFinal = false, int $passMark = 60): int
    {
        $n = ++$this->sequence;

        return $this->env->database->insert('quizzes', [
            'course_id' => $courseId,
            'lesson_id' => $lessonId,
            'title' => 'Quiz ' . $n,
            'opens_at' => $this->nowString(),
            'pass_mark' => $passMark,
            'is_final' => $isFinal ? 1 : 0,
            'show_results' => 'after_submit',
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    protected function seedAttempt(
        int $quizId,
        int $enrolmentId,
        int $userId,
        int $attemptNumber,
        string $status = 'graded',
        ?float $percentage = null,
        ?bool $passed = null,
    ): int {
        return $this->env->database->insert('attempts', [
            'quiz_id' => $quizId,
            'enrolment_id' => $enrolmentId,
            'user_id' => $userId,
            'attempt_number' => $attemptNumber,
            'status' => $status,
            'started_at' => $this->nowString(),
            'submitted_at' => $status === 'in_progress' ? null : $this->nowString(),
            'percentage' => $percentage,
            'passed' => $passed === null ? null : (int) $passed,
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    protected function nowString(): string
    {
        return $this->env->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function lessonProgressRow(int $enrolmentId, int $lessonId): ?array
    {
        return $this->env->database->selectOne(
            Query::from('lesson_progress')
                ->where('enrolment_id', '=', $enrolmentId)
                ->where('lesson_id', '=', $lessonId),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function courseProgressRow(int $enrolmentId): ?array
    {
        return $this->env->database->selectOne(
            Query::from('course_progress')->where('enrolment_id', '=', $enrolmentId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function enrolmentRow(int $enrolmentId): array
    {
        $row = $this->env->database->selectOne(
            Query::from('enrolments')->where('id', '=', $enrolmentId),
        );

        self::assertNotNull($row);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function videoPositionRow(int $userId, int $lessonId): ?array
    {
        return $this->env->database->selectOne(
            Query::from('video_positions')
                ->where('user_id', '=', $userId)
                ->where('lesson_id', '=', $lessonId),
        );
    }

    /**
     * @param class-string<DomainEvent> $eventClass
     *
     * @return list<DomainEvent>
     */
    protected function publishedEvents(string $eventClass): array
    {
        return array_values(array_filter(
            $this->env->eventBridge->published,
            static fn (DomainEvent $event): bool => $event instanceof $eventClass,
        ));
    }
}
