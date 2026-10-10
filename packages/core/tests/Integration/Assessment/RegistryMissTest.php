<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\ManualGradingStatus;
use Shondalai\VidyaLMS\Core\Application\Assessment\ResolvedQuestionType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;
use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;
use Shondalai\VidyaLMS\Core\Tests\Support\RemovableEssayType;
use Shondalai\VidyaLMS\Core\Tests\Support\RemovedEssayPlaceholder;

/**
 * A registry miss is a normal path, and this is the proof.
 *
 * Removing a package must not throw out of submission, out of the learner
 * attempt view, out of manual grading or out of a save; and it must not
 * quietly drop a pending essay out of the instructor's queue either, which
 * is worse, because there is no error anywhere and an attempt with an
 * ungraded essay reads as fully graded.
 */
final class RegistryMissTest extends AssessmentIntegrationTestCase
{
    private int $quizId;
    private int $questionId;

    protected function setUp(): void
    {
        parent::setUp();

        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);
        $this->quizId = $quiz->id;

        // The type is registered while the question is authored, exactly as
        // it would be while the package is installed.
        $this->questionTypes->register(new RemovableEssayType($this->env->translator));

        $question = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, 'x_acme_essay', [
            'prompt' => 'Explain the tide.',
        ], 4.0);
        self::assertNotNull($question->id);
        $this->questionId = $question->id;
    }

    /**
     * Replacing the provider with one that refuses is the documented way to
     * turn a type off, and it is how a removed package is simulated: the key
     * stays resolvable but nothing can read the shape, which is the
     * definition-version half of the miss.
     */
    private function removeThePackage(): void
    {
        $this->questionTypes->replace(new RemovedEssayPlaceholder($this->env->translator));
    }

    public function testTheLearnerAttemptViewStillRendersWithAPlaceholder(): void
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        $this->removeThePackage();

        $view = $this->getAttemptForLearnerUseCase()->execute($this->learner(), $attempt->id);

        self::assertCount(1, $view['questions']);
        // The projection was frozen while the package was installed, so the
        // learner still sees what they were served.
        self::assertSame('Explain the tide.', $view['questions'][0]['definition']['prompt']);
    }

    public function testSubmittingDoesNotThrowAndTheItemIsNotMarkedWrong(): void
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$this->questionId => 'The moon.']);

        $this->removeThePackage();

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(Attempt::STATUS_SUBMITTED, $submitted->status, 'it waits for a human, it does not fail');
        self::assertNull($submitted->passed);

        $grades = $this->attempts->gradesFor($attempt->id);
        self::assertCount(1, $grades);
        self::assertSame(ScoreStatus::NotScored, $grades[0]->scoreStatus);
        self::assertSame(SessionStatus::PendingExternalScoring, $grades[0]->sessionStatus);
        self::assertNotNull($grades[0]->feedback, 'the explanatory row names the missing provider');
        // The row goes through TranslatorPort rather than carrying English
        // built in a use case, so the stub translator echoes the key.
        self::assertSame(NeutralStrings::MISS_UNSUPPORTED_VERSION, $grades[0]->feedback);
    }

    /**
     * The case the four throwing call sites cannot see. The key still
     * resolves, so nothing throws, and the old code would have handed a
     * shape the provider cannot read to a grading formula.
     */
    public function testARowNewerThanTheProviderSupportsIsTreatedAsAMiss(): void
    {
        $resolved = $this->typeResolver->resolve('single_choice', 7);

        self::assertFalse($resolved->isAvailable());
        self::assertSame(ResolvedQuestionType::MISS_UNSUPPORTED_VERSION, $resolved->miss);
        self::assertNotNull($resolved->provider, 'the key still resolves; that is the whole problem');
        self::assertSame(NeutralStrings::MISS_UNSUPPORTED_VERSION, $resolved->missFeedback());
        self::assertStringContainsString(
            'newer format',
            NeutralStrings::english(NeutralStrings::MISS_UNSUPPORTED_VERSION, ['version' => 7, 'type' => 'single_choice']),
            'the catalogue still carries the sentence the key names',
        );
    }

    public function testAnUnsupportedVersionGradesToNotScoredRatherThanZero(): void
    {
        $resolved = $this->typeResolver->resolve('single_choice', 7);

        $result = $resolved->grade(['correct' => 'a'], 'a', GradingContext::detached());

        self::assertSame(0.0, $result->fraction);
        self::assertSame(ScoreStatus::NotScored, $result->scoreStatus);
    }

    public function testTheAttemptStillSubmitsWhenTheAnswerWasNeverGiven(): void
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        $this->removeThePackage();

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(Attempt::STATUS_SUBMITTED, $submitted->status);
    }

    /**
     * The design's own stated test: unregister a provider whose questions
     * have pending manual grades and assert the queue count does not change.
     */
    public function testThePendingCountDoesNotChangeWhenTheProviderGoes(): void
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$this->questionId => 'The moon.']);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $status = $this->manualGradingStatus();
        $before = $status->pendingCountFor($this->reload($attempt->id));

        $this->removeThePackage();

        self::assertSame(1, $before);
        self::assertSame($before, $status->pendingCountFor($this->reload($attempt->id)));
    }

    /**
     * The explicit route out for state already in flight: without it a
     * submitted attempt with a pending item whose package has gone stays
     * submitted with a null pass flag forever.
     */
    public function testAGraderCanCloseAnOrphanedPendingItem(): void
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$this->questionId => 'The moon.']);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $this->removeThePackage();

        $graded = $this->gradeAnswerManuallyUseCase()->execute(
            $this->grader(),
            $attempt->id,
            $this->questionId,
            3.0,
            'Closed: the provider is not installed.',
        );

        self::assertSame(Attempt::STATUS_GRADED, $graded->status);
        self::assertNotNull($graded->passed);
    }

    /**
     * A client rendering claim cannot alter the server's scoring denominator.
     */
    public function testAClientRenderingClaimCannotExemptAQuestionFromScoring(): void
    {
        // A machine-marked item: a human-marked one is pending a human
        // whether or not it rendered, which is already the right answer.
        $choice = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, 'single_choice', [
            'prompt' => 'Pick.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
        ], 1.0);
        self::assertNotNull($choice->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            $choice->id => ['response' => null, 'presentation_form' => 'not_rendered'],
        ]);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $grades = [];

        foreach ($this->attempts->gradesFor($attempt->id) as $grade) {
            $grades[$grade->questionId] = $grade;
        }

        self::assertSame(ScoreStatus::Scored, $grades[$choice->id]->scoreStatus);
        self::assertSame(1.0, $submitted->maxScore);
        self::assertSame(0.0, $submitted->score);
        self::assertNotTrue($submitted->passed);
    }

    private function reload(int $attemptId): Attempt
    {
        $attempt = $this->attempts->byId($attemptId);
        self::assertNotNull($attempt);

        return $attempt;
    }

    private function manualGradingStatus(): ManualGradingStatus
    {
        return new ManualGradingStatus(
            $this->attempts,
            $this->quizzes,
            $this->manifest,
            $this->typeResolver,
        );
    }
}
