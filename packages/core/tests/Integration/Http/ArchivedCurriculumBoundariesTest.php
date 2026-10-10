<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Everything that has to agree with an archive, and used not to.
 *
 * An archived lesson leaves the learner curriculum, the lessons_total of
 * every enrolment and the completion rule. Three places went on ignoring
 * that: a quiz inside an archived lesson still started attempts and still
 * steered the average and the final quiz rule, and a lesson could be
 * published back into an archived module through either the update or the
 * move, which made the course invisible in one direction and uncompletable
 * in the other.
 */
final class ArchivedCurriculumBoundariesTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testALearnerCannotStartAnAttemptOnAnArchivedLessonsQuiz(): void
    {
        $learner = $this->fixture->learner();

        $before = $this->fixture->call('POST', '/quizzes/' . $this->fixture->quizId . '/attempts', $learner);
        self::assertSame(201, $before->status, 'the quiz is live to begin with');

        $this->submitOpenAttempt($learner);

        $archived = $this->fixture->call(
            'DELETE',
            '/manage/lessons/' . $this->fixture->quizLessonId,
            $this->fixture->teacher(),
        );
        self::assertSame(200, $archived->status);

        $after = $this->fixture->call('POST', '/quizzes/' . $this->fixture->quizId . '/attempts', $learner);

        self::assertSame(
            404,
            $after->status,
            'a quiz whose lesson has left the curriculum is not a quiz the learner can take',
        );
    }

    public function testAnArchivedLessonsQuizStopsSteeringTheAverage(): void
    {
        $learner = $this->fixture->learner();

        $this->fixture->call('POST', '/quizzes/' . $this->fixture->quizId . '/attempts', $learner);
        $this->submitOpenAttempt($learner);

        $enrolmentId = $this->enrolmentId();

        $this->fixture->kernel->recomputeCourseProgress->execute($learner, $enrolmentId);
        self::assertNotNull($this->summary($enrolmentId)['quiz_average'], 'the attempt counts while the lesson is live');

        $this->fixture->call(
            'DELETE',
            '/manage/lessons/' . $this->fixture->quizLessonId,
            $this->fixture->teacher(),
        );

        $this->fixture->kernel->recomputeCourseProgress->execute($learner, $enrolmentId);

        self::assertNull(
            $this->summary($enrolmentId)['quiz_average'],
            'the course does not contain that quiz any more, so it cannot hold the average down',
        );
    }

    public function testAnArchivedFinalQuizStopsGoverningCompletion(): void
    {
        $teacher = $this->fixture->teacher();
        $learner = $this->fixture->learner();
        $courseId = $this->fixture->courseId;

        $settings = $this->fixture->call('PUT', '/manage/quizzes/' . $this->fixture->quizId, $teacher, [
            'title' => 'Checkpoint',
            'lesson_id' => $this->fixture->quizLessonId,
            'is_final' => true,
        ]);
        self::assertSame(200, $settings->status);
        self::assertTrue($settings->data['is_final'] ?? false, 'the quiz really is the final one');

        $rule = $this->fixture->call('PATCH', '/manage/courses/' . $courseId, $teacher, [
            'completion_rule' => 'pass_final',
        ]);
        self::assertSame(200, $rule->status);

        $enrolmentId = $this->enrolmentId();
        $this->fixture->kernel->recomputeCourseProgress->execute($learner, $enrolmentId);

        self::assertSame(
            0,
            (int) $this->summary($enrolmentId)['final_quiz_passed'],
            'the final quiz is unpassed while it is part of the course',
        );

        $this->fixture->call('DELETE', '/manage/lessons/' . $this->fixture->quizLessonId, $teacher);
        $this->fixture->kernel->recomputeCourseProgress->execute($learner, $enrolmentId);

        self::assertNull(
            $this->summary($enrolmentId)['final_quiz_passed'],
            'a final quiz that left the course cannot keep every learner from finishing it',
        );
    }

    public function testALessonCannotBePublishedIntoAnArchivedModule(): void
    {
        $teacher = $this->fixture->teacher();

        $this->fixture->call('DELETE', '/manage/modules/' . $this->fixture->moduleId, $teacher);

        $restored = $this->fixture->call('PATCH', '/manage/lessons/' . $this->fixture->lessonId, $teacher, [
            'state' => 'published',
        ]);

        self::assertSame(422, $restored->status, 'restoring the lesson means restoring its module first');
        self::assertSame('archived', $this->lessonState($this->fixture->lessonId));
    }

    public function testAPublishedLessonCannotMoveIntoAnArchivedModule(): void
    {
        $teacher = $this->fixture->teacher();

        $target = $this->fixture->call('POST', '/manage/courses/' . $this->fixture->courseId . '/modules', $teacher, [
            'title' => 'Later',
        ]);
        $targetId = (int) ($target->data['id'] ?? 0);
        self::assertGreaterThan(0, $targetId);

        $this->fixture->call('DELETE', '/manage/modules/' . $targetId, $teacher);

        $moved = $this->fixture->call('POST', '/manage/lessons/' . $this->fixture->lessonId . '/move', $teacher, [
            'module_id' => $targetId,
        ]);

        self::assertSame(422, $moved->status, 'the move is the other route into the same broken state');
    }

    public function testAPublishedLessonInAnArchivedModuleNeverBlocksCompletion(): void
    {
        $teacher = $this->fixture->teacher();
        $learner = $this->fixture->learner();
        $enrolmentId = $this->enrolmentId();

        // The state the two refusals above now prevent, written straight to
        // the rows so an install that already carries it is proved to recover.
        $this->fixture->env->database->update(
            'modules',
            ['state' => 'archived'],
            ['id' => $this->fixture->moduleId],
        );

        $this->fixture->kernel->recomputeCourseProgress->execute($learner, $enrolmentId);

        self::assertSame(
            0,
            (int) $this->summary($enrolmentId)['lessons_total'],
            'an unreachable module contributes nothing to what a learner has to do',
        );

        $curriculum = $this->fixture->call('GET', '/courses/' . $this->fixture->courseId . '/curriculum', $learner);
        self::assertNotNull($curriculum->data);
        self::assertSame([], $curriculum->data['modules'], 'and the learner sees exactly the same nothing');

        // Unused in the assertion, present so the teacher variable is not a
        // silent leftover: the author still sees the module to restore it.
        $authoring = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId . '/curriculum',
            $teacher,
        );
        self::assertNotNull($authoring->data);
        self::assertNotSame([], $authoring->data['modules']);
    }

    private function submitOpenAttempt(Principal $learner): void
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('attempts')
                ->where('quiz_id', '=', $this->fixture->quizId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID)
                ->where('status', '=', 'in_progress'),
        );

        if ($row === null) {
            return;
        }

        $attemptId = (int) $row['id'];
        $this->fixture->call('POST', '/attempts/' . $attemptId . '/submit', $learner);

        // The fixture's only question is a short answer a human marks, so
        // the submitted attempt has no percentage until somebody marks it:
        // an attempt still waiting on a rater is not a result, and averaging
        // the auto-marked half of a paper as the whole of it is what this
        // build stopped doing. Closing the pending answer is what gives the
        // attempt a grade to steer an average with.
        $this->fixture->call(
            'POST',
            '/attempts/' . $attemptId . '/answers/' . $this->fixture->questionId . '/grade',
            $this->fixture->teacher(),
            ['points_awarded' => 5],
        );
    }

    private function lessonState(int $lessonId): string
    {
        $row = $this->fixture->env->database->selectOne(Query::from('lessons')->where('id', '=', $lessonId));

        self::assertNotNull($row);

        return (string) $row['state'];
    }

    private function enrolmentId(): int
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );

        self::assertNotNull($row);

        return (int) $row['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(int $enrolmentId): array
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('course_progress')->where('enrolment_id', '=', $enrolmentId),
        );

        self::assertNotNull($row);

        return $row;
    }
}
