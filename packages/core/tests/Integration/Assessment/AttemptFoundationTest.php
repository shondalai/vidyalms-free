<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The four core changes, proved against the failures they exist to close.
 */
final class AttemptFoundationTest extends AssessmentIntegrationTestCase
{
    /**
     * @return array{int, int} the quiz id and its one question id
     */
    private function quizWithOneChoice(int $passMark = 50): array
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: $passMark);
        self::assertNotNull($quiz->id);

        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'single_choice', [
            'prompt' => 'Pick one.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
        ], 2.0);
        self::assertNotNull($question->id);

        return [$quiz->id, $question->id];
    }

    // ── The frozen served set ────────────────────────────────────────────

    public function testStartingAnAttemptFreezesTheServedQuestions(): void
    {
        [$quizId, $questionId] = $this->quizWithOneChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $frozen = $this->attemptQuestions->forAttempt($attempt->id);

        self::assertCount(1, $frozen);
        self::assertSame($questionId, $frozen[0]->questionId);
        self::assertSame(1, $frozen[0]->position);
        self::assertSame(2.0, $frozen[0]->points);
        self::assertSame(AnsweredStatus::Presented, $frozen[0]->answeredStatus);
        self::assertSame('Pick one.', $frozen[0]->presented['prompt']);
        self::assertNotSame('', $frozen[0]->definitionHash);
    }

    public function testTheFrozenProjectionCarriesNoCorrectness(): void
    {
        [$quizId] = $this->quizWithOneChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $encoded = json_encode($this->attemptQuestions->forAttempt($attempt->id)[0]->presented, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('correct', $encoded);
    }

    public function testTheDefinitionStoreIsContentAddressedAndDeduplicated(): void
    {
        [$quizId] = $this->quizWithOneChoice();

        $first = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($first->id);
        $this->submitAttemptUseCase()->execute($this->learner(), $first->id);

        $second = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($second->id);

        self::assertSame(
            1,
            $this->env->database->count(Query::from('question_definitions')),
            'two attempts of one definition share one row',
        );
    }

    /**
     * The bug the manifest exists to close: an instructor editing a
     * definition mid-attempt used to rewrite how the in-flight attempt was
     * graded.
     */
    public function testEditingAQuestionMidAttemptDoesNotRegradeTheAttemptInFlight(): void
    {
        [$quizId, $questionId] = $this->quizWithOneChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'a']);

        // The teacher moves the key while the learner is mid-attempt.
        $this->updateQuestionUseCase()->execute($this->teacher(), $questionId, [
            'prompt' => 'Pick one.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'b',
        ], 2.0);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(2.0, $submitted->score, 'graded against the definition that was served');
    }

    /**
     * The other half of the same rule: a question published after the
     * attempt started is not graded, because the learner never saw it.
     */
    public function testAQuestionPublishedMidAttemptIsNotGraded(): void
    {
        [$quizId, $questionId] = $this->quizWithOneChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'a']);

        $this->addQuestionUseCase()->execute($this->teacher(), $quizId, 'true_false', [
            'prompt' => 'Added after the start.',
            'correct' => true,
        ], 8.0);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(2.0, $submitted->maxScore, 'the new question is not in the served set');
        self::assertSame(100.0, $submitted->percentage);
    }

    public function testTheAttemptCarriesItsSeedAndItsFrozenPolicy(): void
    {
        [$quizId] = $this->quizWithOneChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);

        self::assertNotNull($attempt->presentationSeed);
        self::assertSame('deferred_feedback', $attempt->behaviour);
        self::assertSame('deferred_feedback', $attempt->policy()->behaviour);
        self::assertFalse($attempt->policy()->shufflesOptions());
    }

    // ── Unscored questions ───────────────────────────────────────────────

    /**
     * The third free-build defect: without questions.scored an opinion scale
     * is either unauthorable or a free mark that moves the pass percentage.
     */
    public function testAnUnscoredQuestionMovesNeitherSumOfTheTotals(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);

        $marked = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'A real question.',
            'correct' => true,
        ], 4.0);

        $survey = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'single_choice', [
            'policy' => ['key' => 'none'],
            'prompt' => 'How did you find this course?',
            'options' => [['key' => 'good', 'label' => 'Good'], ['key' => 'bad', 'label' => 'Bad']],
        ], 6.0, scored: false);

        self::assertFalse($survey->scored);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            self::qid($marked) => true,
            self::qid($survey) => 'good',
        ]);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(4.0, $submitted->score);
        self::assertSame(4.0, $submitted->maxScore, 'the survey question is in neither sum');
        self::assertSame(100.0, $submitted->percentage);
        self::assertCount(2, $this->attempts->gradesFor($attempt->id), 'it still gets a grade row');
    }

    public function testAScaleWithNoRightAnswerIsAuthorable(): void
    {
        $courseId = $this->createCourse();
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $survey = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'single_choice', [
            'policy' => ['key' => 'none'],
            'prompt' => 'Rate this lesson.',
            'options' => [
                ['key' => '1', 'label' => 'Poor'],
                ['key' => '5', 'label' => 'Excellent'],
            ],
        ], 1.0, scored: false);

        self::assertArrayNotHasKey('correct', $survey->definition);
        self::assertSame('none', $survey->scoringPolicy);
    }

    // ── The item that never reached the grading queue ────────────────────

    /**
     * The fifth free-build defect. An essay nobody opened was skipped,
     * written as an automatic zero, and the attempt went straight to graded
     * with the pass flag settled.
     */
    public function testAnUnansweredHumanScoredItemStillReachesTheQueue(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);

        $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'short_answer', [
            'prompt' => 'Explain the tide in one sentence.',
            'accepted' => [],
        ], 5.0);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);

        // The learner never opens it.
        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(Attempt::STATUS_SUBMITTED, $submitted->status);
        self::assertNull($submitted->passed, 'the pass flag is not settled while a human is owed');

        $grades = $this->attempts->gradesFor($attempt->id);
        self::assertCount(1, $grades);
        self::assertSame(SessionStatus::PendingExternalScoring, $grades[0]->sessionStatus);
        self::assertSame(ScoreStatus::NotScored, $grades[0]->scoreStatus);
        self::assertSame(AnsweredStatus::Presented, $grades[0]->answeredStatus);
    }

    public function testTheHumanScoredIntentIsMirroredIntoTheColumn(): void
    {
        $courseId = $this->createCourse();
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $essay = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'short_answer', [
            'prompt' => 'Explain.',
            'accepted' => [],
        ]);
        $auto = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'short_answer', [
            'prompt' => 'Name it.',
            'accepted' => ['Mars'],
        ]);

        self::assertSame('human', $essay->externalScored);
        self::assertSame('machine', $auto->externalScored);
    }

    // ── The derived mirrors ──────────────────────────────────────────────

    public function testTheDerivedColumnsAreWrittenByTheRepository(): void
    {
        [, $questionId] = $this->quizWithOneChoice();

        $question = $this->questions->byId($questionId);
        self::assertInstanceOf(Question::class, $question);

        self::assertSame(1, $question->definitionVersion);
        self::assertSame('single:identifier', $question->responseShape);
        self::assertSame('match_correct', $question->scoringPolicy);
        self::assertTrue($question->scored);
    }

    /**
     * The mirror records the pin a legacy row actually grades under, not the
     * current default, so the column and the marking never disagree.
     */
    public function testALegacyMultipleChoiceRowMirrorsThePlusMinusPin(): void
    {
        $courseId = $this->createCourse();
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        // Written straight through the repository, as a migrated row is:
        // no policy in the definition at all.
        $legacy = $this->questions->add(new Question(
            id: null,
            quizId: $quiz->id,
            type: 'multiple_choice',
            position: 1,
            points: 3.0,
            definition: [
                'prompt' => 'Pick the vowels.',
                'options' => [
                    ['key' => 'a', 'label' => 'a'],
                    ['key' => 'e', 'label' => 'e'],
                    ['key' => 'i', 'label' => 'i'],
                    ['key' => 'k', 'label' => 'k'],
                ],
                'correct' => ['a', 'e', 'i'],
            ],
            state: Question::STATE_PUBLISHED,
        ));

        self::assertSame('plus_minus', $legacy->scoringPolicy);
    }

    public function testALegacyMultipleChoiceRowKeepsItsOldMark(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $legacy = $this->questions->add(new Question(
            id: null,
            quizId: $quiz->id,
            type: 'multiple_choice',
            position: 1,
            points: 3.0,
            definition: [
                'prompt' => 'Pick the vowels.',
                'options' => [
                    ['key' => 'a', 'label' => 'a'],
                    ['key' => 'e', 'label' => 'e'],
                    ['key' => 'i', 'label' => 'i'],
                    ['key' => 'k', 'label' => 'k'],
                ],
                'correct' => ['a', 'e', 'i'],
            ],
            state: Question::STATE_PUBLISHED,
        ));
        self::assertNotNull($legacy->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$legacy->id => ['a', 'e', 'k']]);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        // max(0, 2 - 1) / 3 of three points, exactly as before.
        self::assertSame(1.0, $submitted->score);
    }

    /**
     * The asymmetry that makes the pin work: creating stamps the current
     * default, editing never does.
     */
    public function testEditingAnExistingQuestionDoesNotStampTheCurrentDefault(): void
    {
        $courseId = $this->createCourse();
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $legacy = $this->questions->add(new Question(
            id: null,
            quizId: $quiz->id,
            type: 'multiple_choice',
            position: 1,
            points: 3.0,
            definition: [
                'prompt' => 'Pick the vowels.',
                'options' => [
                    ['key' => 'a', 'label' => 'a'],
                    ['key' => 'e', 'label' => 'e'],
                    ['key' => 'k', 'label' => 'k'],
                ],
                'correct' => ['a', 'e'],
            ],
            state: Question::STATE_PUBLISHED,
        ));
        self::assertNotNull($legacy->id);

        $revised = $this->updateQuestionUseCase()->execute($this->teacher(), $legacy->id, [
            'prompt' => 'Pick the vowels, revised.',
            'options' => [
                ['key' => 'a', 'label' => 'a'],
                ['key' => 'e', 'label' => 'e'],
                ['key' => 'k', 'label' => 'k'],
            ],
            'correct' => ['a', 'e'],
        ], 3.0);

        self::assertArrayNotHasKey('policy', $revised->definition);
        self::assertSame('plus_minus', $this->questions->byId($legacy->id)?->scoringPolicy);
    }

    // ── Save-time validity ───────────────────────────────────────────────

    /**
     * An invalid answer is stored, not refused. Refusing it wedges the whole
     * attempt, because the runner posts the entire answer map on every
     * autosave.
     */
    public function testAnInvalidAnswerIsStoredAndReportedRatherThanRefused(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $capped = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'multiple_choice', [
            'prompt' => 'Pick at most two.',
            'options' => [
                ['key' => 'a', 'label' => 'a'],
                ['key' => 'b', 'label' => 'b'],
                ['key' => 'c', 'label' => 'c'],
                ['key' => 'd', 'label' => 'd'],
            ],
            'correct' => ['a', 'b'],
            'max_selections' => 2,
            'max_message' => 'Choose no more than two.',
        ]);
        $other = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'A second question.',
            'correct' => true,
        ]);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);

        $report = $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            self::qid($capped) => ['a', 'b', 'c'],
            self::qid($other) => true,
        ]);

        self::assertFalse($report[self::qid($capped)]->isValid());
        self::assertSame('Choose no more than two.', $report[self::qid($capped)]->errors[0]->message);
        self::assertTrue($report[self::qid($other)]->isValid());

        // Both are stored: nothing else the learner wrote is lost over one
        // invalid key.
        $answers = $this->attempts->answersFor($attempt->id);
        self::assertSame(['a', 'b', 'c'], $answers[self::qid($capped)]);
        self::assertTrue($answers[self::qid($other)]);
    }

    public function testAnInvalidAnswerIsRecordedAsAttemptedNotAnswered(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $capped = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'multiple_choice', [
            'prompt' => 'Pick at most one.',
            'options' => [
                ['key' => 'a', 'label' => 'a'],
                ['key' => 'b', 'label' => 'b'],
                ['key' => 'c', 'label' => 'c'],
            ],
            'correct' => ['a'],
            'max_selections' => 1,
        ]);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [self::qid($capped) => ['a', 'b']]);

        $frozen = $this->attemptQuestions->forAttempt($attempt->id);

        self::assertSame(AnsweredStatus::Attempted, $frozen[0]->answeredStatus);
    }

    public function testAutosavesKeepOnlyTheCurrentDraft(): void
    {
        [$quizId, $questionId] = $this->quizWithOneChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'a']);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'b']);

        // Draft edits replace the previous unprocessed event; checked tries remain.
        self::assertSame(1, $this->eventCount($attempt->id, $questionId));

        // **Neither of them is a try.** A try is a response submitted for
        // processing, and the runner posts the whole answer map on every
        // autosave: counting changed autosaves made the try penalty charge a
        // learner for changing their mind, so a learner who answered true,
        // false, true, false, true and never checked anything scored 2 of 10
        // for a correct answer while one who never revised paid nothing.
        self::assertSame(1, $this->attempts->tryCountFor($attempt->id, $questionId));

        // Both representations keep the latest answer.
        self::assertSame('b', $this->attempts->answersFor($attempt->id)[$questionId]);
    }

    /**
     * A response submitted for processing is a try, and it is the only thing
     * that is.
     */
    public function testOnlyAResponseSubmittedForProcessingCountsAsATry(): void
    {
        [$quizId, $questionId] = $this->quizWithOneChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            $questionId => ['response' => 'a', 'processed' => true],
        ]);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            $questionId => ['response' => 'b', 'processed' => true],
        ]);

        // Two checks plus the submit itself.
        self::assertSame(3, $this->attempts->tryCountFor($attempt->id, $questionId));
    }

    private function eventCount(int $attemptId, int $questionId): int
    {
        return $this->env->database->count(
            Query::from('attempt_answer_events')
                ->where('attempt_id', '=', $attemptId)
                ->where('question_id', '=', $questionId),
        );
    }

    /**
     * A try is a change of answer, not a save.
     *
     * The runner posts the whole answer map on every autosave, so recording an
     * event per posted key made the try count the number of autosaves. That is
     * the number a try penalty multiplies and the number the per-item timing is
     * reconstructed from, so a learner who left one radio button alone for four
     * minutes was billed for four tries at it.
     */
    public function testAnIdenticalAutosaveDoesNotCountAsAnotherTry(): void
    {
        [$quizId, $questionId] = $this->quizWithOneChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'a']);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'a']);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'a']);

        self::assertSame(
            1,
            $this->eventCount($attempt->id, $questionId),
            'three autosaves of one unchanged answer write one event',
        );

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'b']);

        self::assertSame(
            1,
            $this->eventCount($attempt->id, $questionId),
            'changing the draft replaces its unprocessed event',
        );

        self::assertSame(
            1,
            $this->attempts->tryCountFor($attempt->id, $questionId),
            'neither autosave was submitted for processing, so neither is a try',
        );
    }

    /**
     * The four columns that had nowhere to live. raw_input in particular is
     * what precision marking has to judge on and is impossible to backfill.
     */
    public function testTheAnswerEnvelopeCarriesTheFourNewFields(): void
    {
        [$quizId, $questionId] = $this->quizWithOneChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            $questionId => [
                'response' => 'a',
                'ui_state' => ['scroll' => 40],
                'raw_input' => ' a ',
                'presentation_form' => 'keyboard',
                'certainty' => 2,
            ],
        ]);

        $stored = $this->attempts->submissionsFor($attempt->id)[$questionId];

        self::assertSame('a', $stored->response);
        self::assertSame(['scroll' => 40], $stored->uiState);
        self::assertSame(' a ', $stored->rawInput);
        self::assertSame('keyboard', $stored->presentationForm);
        self::assertSame(2, $stored->certainty);
    }

    public function testTheBareLegacyAnswerShapeStillWorks(): void
    {
        [$quizId, $questionId] = $this->quizWithOneChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'a']);

        $stored = $this->attempts->submissionsFor($attempt->id)[$questionId];

        self::assertSame('a', $stored->response);
        self::assertSame('interactive', $stored->presentationForm);
    }

    // ── Screens and stimuli ──────────────────────────────────────────────

    public function testEachQuestionIsItsOwnScreenWhenNothingSharesAStimulus(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', ['prompt' => 'One.', 'correct' => true]);
        $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', ['prompt' => 'Two.', 'correct' => true]);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);

        $view = $this->getAttemptForLearnerUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(1, $view['questions'][0]['screen']);
        self::assertSame(2, $view['questions'][1]['screen']);
        self::assertSame([], $view['stimuli']);
    }

    public function testConsecutiveQuestionsSharingAStimulusFormOneScreen(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $now = $this->now();
        $stimulusId = $this->env->database->insert('stimuli', [
            'course_id' => $courseId,
            'title' => 'A short passage',
            'body' => 'The tide turns twice a day.',
            'media' => null,
            'alt_text' => null,
            'long_description' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $first = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', ['prompt' => 'One.', 'correct' => true]);
        $second = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', ['prompt' => 'Two.', 'correct' => true]);

        foreach ([$first, $second] as $question) {
            $this->env->database->update('questions', ['stimulus_id' => $stimulusId], ['id' => self::qid($question)]);
        }

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);

        $view = $this->getAttemptForLearnerUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(1, $view['questions'][0]['screen']);
        self::assertSame(1, $view['questions'][1]['screen'], 'one passage, one screen');
        self::assertCount(1, $view['stimuli']);
        self::assertSame('A short passage', $view['stimuli'][0]['title']);
    }
}
