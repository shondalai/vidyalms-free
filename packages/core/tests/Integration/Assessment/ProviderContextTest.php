<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\LearnerAccessibilityProfile;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\AccessibilityProfile;
use Shondalai\VidyaLMS\Core\Tests\Support\ContextRecordingType;

/**
 * A context object populated with hardcoded defaults is indistinguishable
 * from no context at all.
 *
 * The three call sites that build contexts all passed defaults: the decode
 * context had no locale, so a de-DE learner's "5,5" was parsed under en-GB
 * rules at save-time validation; the grading context had no locale and no
 * certainty, because the answers were read back as bare responses and the
 * certainty column was discarded; and the presentation context had a null
 * accessibility profile with the shuffle resolution passing the literal
 * false, which made the profile-suppressed branch unreachable by
 * construction.
 *
 * One test per axis, asserting on what a provider actually receives.
 */
final class ProviderContextTest extends AssessmentIntegrationTestCase
{
    private const DE = 'de-DE';

    private ContextRecordingType $recorder;
    private int $quizId;
    private int $questionId;

    protected function setUp(): void
    {
        parent::setUp();

        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);
        $this->quizId = $quiz->id;

        $this->recorder = new ContextRecordingType();
        $this->questionTypes->register($this->recorder);

        $question = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, ContextRecordingType::KEY, [
            'prompt' => 'Give a number.',
        ], 1.0);
        self::assertNotNull($question->id);
        $this->questionId = $question->id;
    }

    public function testTheRequestLocaleReachesTheDecodeContext(): void
    {
        $this->useLocale(self::DE);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$this->questionId => '5,5']);

        self::assertSame(1, $this->recorder->validations, 'a type that declares only its shape is still asked');
        $decode = $this->recorder->lastDecode;
        self::assertNotNull($decode);
        self::assertSame(self::DE, $decode->locale);
        self::assertSame(
            ',',
            $decode->decimalSeparator(),
            'the separator a numeric-bearing primitive parses with follows the request locale',
        );
    }

    public function testTheRequestLocaleReachesTheGradingContext(): void
    {
        $this->useLocale(self::DE);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$this->questionId => 'x']);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $grading = $this->recorder->lastGrading;
        self::assertNotNull($grading);
        self::assertSame(self::DE, $grading->locale);
        self::assertSame(self::DE, $grading->toScoringContext()->locale);
    }

    public function testTheDeclaredCertaintyReachesTheScoringContext(): void
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            $this->questionId => ['response' => 'x', 'certainty' => 3],
        ]);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $grading = $this->recorder->lastGrading;
        self::assertNotNull($grading);
        self::assertSame(3, $grading->certainty, 'the certainty on the answer row reaches grading');
        self::assertSame(3, $grading->toScoringContext()->certainty);
    }

    public function testASuppressingProfileReachesPresentationAndSuppressesShuffle(): void
    {
        $this->declareProfile(self::LEARNER_ID, [AccessibilityProfile::LAYOUT_SIMPLIFIED => true]);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        self::assertNotNull($this->recorder->lastPresentation);
        self::assertSame(
            AccessibilityProfile::LAYOUT_SIMPLIFIED,
            $this->recorder->lastPresentation->accessibilityProfile,
            'the profile reaches the provider rather than staying null forever',
        );

        $reloaded = $this->attempts->byId($attempt->id);
        self::assertNotNull($reloaded);
        self::assertTrue(
            $reloaded->policy()->accessibilityProfile()->suppressesShuffle(),
            'and it is frozen with the policy, so changing it mid-attempt reshuffles nothing',
        );

        // The recorder shuffles by default, so with no profile the sequence
        // exists; with a suppressing profile it must not.
        $served = $this->manifest->forAttempt($reloaded);
        self::assertCount(1, $served);
        self::assertSame([], $served[0]->choiceSequence->toArray(), 'a simplified layout suppresses option shuffle');
    }

    public function testWithoutAProfileTheShuffleStillHappens(): void
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        $reloaded = $this->attempts->byId($attempt->id);
        self::assertNotNull($reloaded);

        self::assertFalse($reloaded->policy()->accessibilityProfile()->isDeclared());
        self::assertNotNull($this->recorder->lastPresentation);
        self::assertNull($this->recorder->lastPresentation->accessibilityProfile);
    }

    private function useLocale(string $locale): void
    {
        $this->env->locale->use($locale);
    }

    /**
     * @param array<string, mixed> $elements
     */
    private function declareProfile(int $userId, array $elements): void
    {
        $now = $this->now();

        $this->env->database->insert('settings', [
            'name' => LearnerAccessibilityProfile::SETTING_PREFIX . $userId,
            'value' => json_encode($elements, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
