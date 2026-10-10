<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityEngine;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Provider\Availability\CompletionCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\DateCondition;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\UnlockNoticeTask;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The unlock notice, which needs no new machinery and gets none.
 */
final class UnlockNoticeTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $enrolmentId = 0;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 10:00:00');

        $row = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );

        self::assertNotNull($row);
        $this->enrolmentId = (int) $row['id'];
    }

    /**
     * A write path schedules it and a read never does: a GET that schedules
     * breaks the query budget, and a learner who never opened the course
     * before the instant would never be told at all.
     */
    public function testTheRecomputeSchedulesFromTheComposedInstant(): void
    {
        $this->gate($this->fixture->quizLessonId, ['from' => '2026-05-01T09:00:00+00:00', 'until' => null]);

        $this->fixture->kernel->recomputeCourseProgress->execute($this->fixture->manager(), $this->enrolmentId);

        $task = $this->scheduledFor($this->fixture->quizLessonId);

        self::assertNotNull($task);
        self::assertSame(UnlockNoticeTask::TYPE, $task->type);
        self::assertSame('2026-05-01T09:00:00+00:00', $task->runAt->format(\DateTimeInterface::ATOM));
    }

    /**
     * Scheduling from the leaf is the obvious shortcut and it is wrong: under
     * a date and a prerequisite together, the date leaf names an instant
     * while the node names none, so a leaf-driven task would tell a learner
     * an item is open while they still cannot enter it.
     */
    public function testANodeWithAPrerequisiteNamesNoInstantAndSchedulesNothing(): void
    {
        $this->fixture->kernel->manageAvailabilityRule->put(
            $this->fixture->teacher(),
            new GateSubject(GateSubject::TYPE_LESSON, $this->fixture->quizLessonId),
            \Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface::ContentGating,
            [
                'op' => 'and',
                'c' => [
                    [
                        'type' => DateCondition::KEY,
                        'payload' => StoredShape::wrap(['from' => '2026-05-01T09:00:00+00:00', 'tz' => 'UTC'], 1),
                    ],
                    [
                        'type' => CompletionCondition::KEY,
                        'payload' => StoredShape::wrap([
                            'target' => ['kind' => 'lesson', 'id' => $this->fixture->lessonId],
                            'state' => CompletionCondition::STATE_COMPLETE,
                        ], 1),
                    ],
                ],
            ],
        );

        $this->fixture->kernel->recomputeCourseProgress->execute($this->fixture->manager(), $this->enrolmentId);

        self::assertNull($this->scheduledFor($this->fixture->quizLessonId));
    }

    /**
     * The handler re-evaluates and may emit nothing. The scheduler's
     * punctuality is not a guarantee anything relies on.
     */
    public function testTheHandlerEmitsNothingWhenTheSubjectIsStillBlocked(): void
    {
        $this->gate($this->fixture->quizLessonId, ['from' => '2026-05-01T09:00:00+00:00', 'until' => null]);

        $before = \count($this->fixture->env->eventBridge->published);

        $summary = $this->fixture->kernel->runScheduledTask(
            UnlockNoticeTask::TYPE,
            1,
            [
                'enrolment_id' => $this->enrolmentId,
                'course_id' => $this->fixture->courseId,
                'subject_type' => GateSubject::TYPE_LESSON,
                'subject_id' => $this->fixture->quizLessonId,
            ],
            $this->fixture->manager(),
        );

        self::assertSame('Unlock notice withheld: the subject is still blocked.', $summary);
        self::assertCount($before, $this->fixture->env->eventBridge->published);
    }

    public function testTheHandlerEmitsWhenTheSubjectIsOpen(): void
    {
        $this->gate($this->fixture->quizLessonId, ['from' => '2026-03-01T09:00:00+00:00', 'until' => null]);

        $summary = $this->fixture->kernel->runScheduledTask(
            UnlockNoticeTask::TYPE,
            1,
            [
                'enrolment_id' => $this->enrolmentId,
                'course_id' => $this->fixture->courseId,
                'subject_type' => GateSubject::TYPE_LESSON,
                'subject_id' => $this->fixture->quizLessonId,
            ],
            $this->fixture->manager(),
        );

        self::assertStringContainsString('Unlock notice emitted', (string) $summary);

        $names = array_map(
            static fn (object $event): string => $event->eventName(),
            $this->fixture->env->eventBridge->published,
        );
        self::assertContains('vidyalms.content.unlocked', $names);
    }

    public function testTheDedupeKeyIsStableSoARescheduleReplacesRatherThanDuplicating(): void
    {
        $subject = new GateSubject(GateSubject::TYPE_LESSON, $this->fixture->quizLessonId);

        self::assertSame(
            'availability.unlock:' . $this->enrolmentId . ':lesson:' . $this->fixture->quizLessonId,
            AvailabilityEngine::unlockDedupeKey($this->enrolmentId, $subject),
        );
    }

    /**
     * @param array<string, mixed> $window
     */
    private function gate(int $lessonId, array $window): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/manage/lessons/' . $lessonId . '/availability',
            $this->fixture->teacher(),
            [
                'tree' => [
                    'op' => 'and',
                    'c' => [[
                        'type' => DateCondition::KEY,
                        'payload' => StoredShape::wrap($window + ['tz' => 'UTC'], 1),
                    ]],
                ],
            ],
        );

        self::assertSame(200, $response->status);
    }

    private function scheduledFor(int $lessonId): ?ScheduledTask
    {
        $key = AvailabilityEngine::unlockDedupeKey(
            $this->enrolmentId,
            new GateSubject(GateSubject::TYPE_LESSON, $lessonId),
        );

        foreach ($this->fixture->env->scheduler->tasks as $task) {
            if ($task->dedupeKey === $key) {
                return $task;
            }
        }

        return null;
    }
}
