<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptSubmitted;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class SubmitAttemptTest extends AssessmentIntegrationTestCase
{
    public function testNineteenForgedRenderingFailuresCannotTurnOneRightAnswerIntoAPass(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 80);
        self::assertNotNull($quiz->id);
        $answers = [];
        for ($i = 0; $i < 20; $i++) {
            $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
                'prompt' => 'Question ' . $i, 'correct' => true,
            ], 1.0);
            $answers[self::qid($question)] = $i === 0 ? true : [
                'response' => null, 'presentation_form' => 'not_rendered',
            ];
        }
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, $answers);
        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);
        self::assertSame(20.0, $submitted->maxScore);
        self::assertSame(1.0, $submitted->score);
        self::assertSame(5.0, $submitted->percentage);
        self::assertFalse($submitted->passed);
        self::assertFalse($this->submittedEvents()[0]->passed);
    }

    /**
     * @return list<AttemptSubmitted>
     */
    private function submittedEvents(): array
    {
        return array_values(array_filter(
            $this->env->eventBridge->published,
            static fn (object $event): bool => $event instanceof AttemptSubmitted,
        ));
    }

    /**
     * The multiple-choice expectation here changed, and the old one was the
     * defect.
     *
     * The learner ticks two of the three keys plus one distractor on an item
     * with a single distractor. Under the free build's original formula,
     * max(0, C - I) / |K|, that paid a third of the marks. A newly authored
     * item now defaults to guess_neutral, max(0, C - I * |K| / |D|) / |K|,
     * whose penalty weight on a three-key one-distractor item is 3, so the
     * same response earns nothing. That is the point of the default: the
     * original formula pays 0.667 to a learner who simply ticks everything,
     * and guess_neutral is the only published rule in the family that makes
     * ticking everything score exactly zero for any option balance.
     *
     * The pass mark moves with it so the assertion still exercises a pass:
     * the same learner scores 38.46 percent rather than 46.15.
     */
    public function testGradesTheFullQuestionMatrixServerSide(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $lessonId = $this->createLesson($courseId);
        $quiz = $this->createQuiz($courseId, passMark: 30, lessonId: $lessonId);
        self::assertNotNull($quiz->id);
        $add = $this->addQuestionUseCase();

        $single = $add->execute($this->teacher(), $quiz->id, 'single_choice', [
            'prompt' => 'Pick the first letter.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
        ], 2.0);

        $multiple = $add->execute($this->teacher(), $quiz->id, 'multiple_choice', [
            'prompt' => 'Pick the vowels.',
            'options' => [
                ['key' => 'a', 'label' => 'a'],
                ['key' => 'e', 'label' => 'e'],
                ['key' => 'i', 'label' => 'i'],
                ['key' => 'k', 'label' => 'k'],
            ],
            'correct' => ['a', 'e', 'i'],
        ], 3.0);

        $boolean = $add->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Water is wet.',
            'correct' => true,
        ], 1.0);

        $short = $add->execute($this->teacher(), $quiz->id, 'short_answer', [
            'prompt' => 'Name the red planet.',
            'accepted' => ['Mars'],
        ], 2.0);

        $blank = $add->execute($this->teacher(), $quiz->id, 'fill_blank', [
            'prompt' => 'Fill [[one]] and [[two]].',
            'blanks' => [
                ['key' => 'one', 'accepted' => ['alpha']],
                ['key' => 'two', 'accepted' => ['beta']],
            ],
        ], 2.0);

        $ordering = $add->execute($this->teacher(), $quiz->id, 'ordering', [
            'prompt' => 'Sort ascending.',
            'items' => [
                ['key' => 'one', 'label' => '1'],
                ['key' => 'two', 'label' => '2'],
                ['key' => 'three', 'label' => '3'],
            ],
            'correct' => ['one', 'two', 'three'],
        ], 3.0);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            self::qid($single) => 'a',
            self::qid($multiple) => ['a', 'e', 'k'],
            self::qid($boolean) => true,
            self::qid($short) => 'Venus',
            self::qid($blank) => ['one' => 'ALPHA', 'two' => 'gamma'],
            self::qid($ordering) => ['one', 'three', 'two'],
        ]);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        // 2 + 0 + 1 + 0 + 1 + 1 = 5 of 13. The multiple-choice item pays
        // nothing: max(0, 2 - 1 * 3/1) / 3 is 0.
        self::assertSame(Attempt::STATUS_GRADED, $submitted->status);
        self::assertSame(5.0, $submitted->score);
        self::assertSame(13.0, $submitted->maxScore);
        self::assertSame(38.46, $submitted->percentage);
        self::assertTrue($submitted->passed);
        self::assertSame($this->now(), $submitted->submittedAt?->format('Y-m-d H:i:s'));

        $grades = $this->attempts->gradesFor($attempt->id);
        self::assertCount(6, $grades);

        foreach ($grades as $grade) {
            self::assertNull($grade->graderId);
        }

        $events = $this->submittedEvents();
        self::assertCount(1, $events);
        self::assertSame($attempt->id, $events[0]->attemptId);
        self::assertSame($quiz->id, $events[0]->quizId);
        self::assertSame($lessonId, $events[0]->lessonId);
        self::assertSame($courseId, $events[0]->courseId);
        self::assertSame($attempt->enrolmentId, $events[0]->enrolmentId);
        self::assertSame(self::LEARNER_ID, $events[0]->userId);
        self::assertSame(38.46, $events[0]->percentage);
        self::assertTrue($events[0]->passed);
        self::assertFalse($events[0]->requiresManualGrading);
    }

    public function testUnansweredQuestionsGradeAsZero(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);

        $answered = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'One.',
            'correct' => true,
        ], 5.0);
        $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Two.',
            'correct' => true,
        ], 5.0);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [self::qid($answered) => true]);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(5.0, $submitted->score);
        self::assertSame(10.0, $submitted->maxScore);
        self::assertSame(50.0, $submitted->percentage);
        self::assertTrue($submitted->passed);
        self::assertCount(2, $this->attempts->gradesFor($attempt->id));
    }

    public function testFailingThePassMark(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 60);
        self::assertNotNull($quiz->id);
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Hard one.',
            'correct' => true,
        ]);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [self::qid($question) => false]);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertFalse($submitted->passed);
        self::assertSame(0.0, $submitted->percentage);
    }

    public function testZeroPassMarkAlwaysPasses(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Any.',
            'correct' => true,
        ]);
        self::assertNotNull($question->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertTrue($submitted->passed);
    }

    public function testQuizWithoutQuestionsScoresZeroPercent(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(0.0, $submitted->score);
        self::assertSame(0.0, $submitted->maxScore);
        self::assertSame(0.0, $submitted->percentage);
        self::assertTrue($submitted->passed);
    }

    public function testManualQuestionLeavesTheAttemptSubmittedWithNullPassed(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);

        $auto = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Auto.',
            'correct' => true,
        ]);
        $manual = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'short_answer', [
            'prompt' => 'Essay-ish.',
            'accepted' => [],
        ], 5.0);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            self::qid($auto) => true,
            self::qid($manual) => 'A thoughtful answer.',
        ]);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(Attempt::STATUS_SUBMITTED, $submitted->status);
        self::assertNull($submitted->passed);

        $events = $this->submittedEvents();
        self::assertCount(1, $events);
        self::assertTrue($events[0]->requiresManualGrading);
        self::assertNull($events[0]->passed);
    }

    public function testSecondSubmitConflictsAndEmitsNoSecondEvent(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        try {
            $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);
            self::fail('A second submit must conflict.');
        } catch (ConflictException) {
        }

        self::assertCount(1, $this->submittedEvents());
        // The failed replay wrote no additional grade rows.
        self::assertSame(0, $this->env->database->count(
            Query::from('attempt_grades')->where('attempt_id', '=', $attempt->id),
        ));
    }

    public function testOnlyTheOwnerMaySubmit(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);

        $this->expectException(NotAuthorizedException::class);
        $this->submitAttemptUseCase()->execute(Principal::user(self::OTHER_LEARNER_ID), $attempt->id);
    }

    public function testLateSubmissionGradesOnlyTheAnswersStoredInTime(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 60, timeLimitSeconds: 600);
        self::assertNotNull($quiz->id);

        $inTime = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Answered before the deadline.',
            'correct' => true,
        ], 5.0);
        $tooLate = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Attempted after the deadline.',
            'correct' => true,
        ], 5.0);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [self::qid($inTime) => true]);

        $this->env->clock->advance('PT11M1S');

        try {
            $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [self::qid($tooLate) => true]);
            self::fail('A save past deadline plus grace must conflict.');
        } catch (ConflictException) {
        }

        // No answer saved after the refusal exists server-side.
        self::assertSame([self::qid($inTime) => true], $this->attempts->answersFor($attempt->id));

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        // The late submission still succeeds through the normal grading
        // path, grading only what was stored in time.
        self::assertSame(Attempt::STATUS_GRADED, $submitted->status);
        self::assertSame(5.0, $submitted->score);
        self::assertSame(10.0, $submitted->maxScore);
        self::assertSame(50.0, $submitted->percentage);
        self::assertFalse($submitted->passed);
        self::assertCount(2, $this->attempts->gradesFor($attempt->id));
    }
}
