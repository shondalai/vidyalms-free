<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\ResolvedQuestionType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Tests\Support\VersionedProbeType;

/**
 * The version gate must be asked about the version of the definition body it
 * is actually holding, never about the questions.definition_version column.
 *
 * The column is the batch-upgrade index: it is re-stamped only when a row is
 * written. A row that is read but never re-saved therefore reports version N
 * while carrying a version N+1 body, and asking the gate about the column
 * lets exactly the regrade the gate exists to close happen anyway: remove the
 * decoration and the older provider reads a shape it does not understand,
 * awards zero, and reports a normal score status. Data survives; marks do
 * not.
 *
 * It fires in the other direction too, on any provider that legitimately
 * raises its own version: every stored row keeps the old column value while
 * supportedDefinitionVersions() moves on, so every question routes to the
 * placeholder at notscored until something happens to re-save it.
 */
final class DefinitionVersionGateTest extends AssessmentIntegrationTestCase
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

        $this->questionTypes->register(VersionedProbeType::v1());

        $question = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, VersionedProbeType::KEY, [
            'prompt' => 'Pick.',
            'accepted' => ['blue'],
        ], 1.0);
        self::assertNotNull($question->id);
        $this->questionId = $question->id;
    }

    public function testAReadRowCarriesTheVersionOfTheBodyItHolds(): void
    {
        $this->questionTypes->replace(VersionedProbeType::v2());

        $question = $this->questions->byId($this->questionId);

        self::assertNotNull($question);
        self::assertArrayHasKey('matchers', $question->definition, 'the upgrade ran on read');
        self::assertArrayNotHasKey('accepted', $question->definition);
        self::assertSame(
            2,
            $question->definitionVersion,
            'the row reports the version of the body it holds, not the stale column',
        );
    }

    public function testTheDecoratedProviderStillGradesItsOwnUpgradedShape(): void
    {
        $this->questionTypes->replace(VersionedProbeType::v2());

        $question = $this->questions->byId($this->questionId);
        self::assertNotNull($question);

        $resolved = $this->typeResolver->forQuestion($question);

        self::assertTrue($resolved->isAvailable());
    }

    /**
     * The probe, end to end. The attempt is frozen while the decoration is
     * installed, so the manifest stores an upgraded body; taking the
     * decoration away must route the item to notscored rather than handing
     * the newer body to a provider that reads a field it no longer has and
     * marking it wrong.
     */
    public function testRemovingTheDecorationRoutesToNotScoredRatherThanAScoredZero(): void
    {
        $this->questionTypes->replace(VersionedProbeType::v2());

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$this->questionId => 'blue']);

        $frozen = $this->manifest->forAttempt($this->reload($attempt->id));
        self::assertCount(1, $frozen);
        self::assertSame(2, $frozen[0]->definitionVersion, 'the frozen definition carries the body it froze');

        $this->questionTypes->replace(VersionedProbeType::v1());

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $grades = $this->attempts->gradesFor($attempt->id);
        self::assertCount(1, $grades);
        self::assertSame(
            ScoreStatus::NotScored,
            $grades[0]->scoreStatus,
            'the older provider cannot read the newer body, so nothing is scored',
        );
        self::assertSame(0.0, $submitted->maxScore, 'a notscored item leaves both sums alone');
    }

    /**
     * The same defect in the other direction: a provider raising its own
     * version must not strand every question it already owns.
     */
    public function testRaisingTheProvidersOwnVersionDoesNotStrandStoredRows(): void
    {
        $this->questionTypes->replace(VersionedProbeType::v2());

        $question = $this->questions->byId($this->questionId);
        self::assertNotNull($question);

        $resolved = $this->typeResolver->forQuestion($question);

        self::assertSame(ResolvedQuestionType::MISS_NONE, $resolved->miss);
        self::assertTrue(
            $resolved->grade($question->definition, 'blue', $this->detachedGradingContext())->fraction > 0.0,
            'a stored row still marks after its own provider moves forward',
        );
    }

    private function detachedGradingContext(): \Shondalai\VidyaLMS\Core\Registry\Context\GradingContext
    {
        return \Shondalai\VidyaLMS\Core\Registry\Context\GradingContext::detached($this->scoringPolicies);
    }

    private function reload(int $attemptId): \Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt
    {
        $attempt = $this->attempts->byId($attemptId);
        self::assertNotNull($attempt);

        return $attempt;
    }
}
