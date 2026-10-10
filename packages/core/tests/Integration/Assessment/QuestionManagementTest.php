<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class QuestionManagementTest extends AssessmentIntegrationTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function trueFalseDefinition(): array
    {
        return ['prompt' => 'The moon orbits the earth.', 'correct' => true];
    }

    public function testQuestionsGetSequentialPositions(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $first = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', $this->trueFalseDefinition());
        $second = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', $this->trueFalseDefinition(), 2.5);

        self::assertSame(1, $first->position);
        self::assertSame(2, $second->position);
        self::assertSame(2.5, $second->points);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $this->expectException(ValidationException::class);
        $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'essay', ['prompt' => 'Write.']);
    }

    public function testInvalidDefinitionIsRejected(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $this->expectException(ValidationException::class);
        $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', ['prompt' => 'No answer given.']);
    }

    public function testPointsMustBePositive(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $this->expectException(ValidationException::class);
        $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', $this->trueFalseDefinition(), 0.0);
    }

    public function testNonTeacherCannotAddQuestions(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $this->expectException(NotAuthorizedException::class);
        $this->addQuestionUseCase()->execute($this->learner(), $quiz->id, 'true_false', $this->trueFalseDefinition());
    }

    public function testUpdateRevalidatesTheDefinition(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', $this->trueFalseDefinition());
        self::assertNotNull($question->id);

        $this->expectException(ValidationException::class);
        $this->updateQuestionUseCase()->execute($this->teacher(), $question->id, ['prompt' => 'Broken.'], 1.0);
    }

    public function testUpdateStoresTheRevision(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', $this->trueFalseDefinition());
        self::assertNotNull($question->id);

        $revised = $this->updateQuestionUseCase()->execute(
            $this->teacher(),
            $question->id,
            ['prompt' => 'The moon orbits the sun.', 'correct' => false],
            4.0,
        );

        self::assertSame(4.0, $revised->points);

        $reloaded = $this->questions->byId($question->id);
        self::assertNotNull($reloaded);
        self::assertSame(4.0, $reloaded->points);
        self::assertFalse((bool) ($reloaded->definition['correct'] ?? true));
    }

    public function testRemoveMarksTheQuestionRemovedButKeepsTheRow(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', $this->trueFalseDefinition());
        self::assertNotNull($question->id);

        $this->removeQuestionUseCase()->execute($this->teacher(), $question->id);

        self::assertSame(1, $this->env->database->count(Query::from('questions')->where('id', '=', $question->id)));

        $reloaded = $this->questions->byId($question->id);
        self::assertNotNull($reloaded);
        self::assertSame(Question::STATE_REMOVED, $reloaded->state);
        self::assertSame([], $this->questions->publishedForQuiz($quiz->id));

        // Removing again is a no-op.
        $this->removeQuestionUseCase()->execute($this->teacher(), $question->id);
        self::assertSame(Question::STATE_REMOVED, $this->questions->byId($question->id)?->state);
    }

    public function testRemovedQuestionCannotBeUpdated(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', $this->trueFalseDefinition());
        self::assertNotNull($question->id);
        $this->removeQuestionUseCase()->execute($this->teacher(), $question->id);

        $this->expectException(ConflictException::class);
        $this->updateQuestionUseCase()->execute($this->teacher(), $question->id, $this->trueFalseDefinition(), 1.0);
    }

    /**
     * The design pairs the two: a question whose policy is none scores
     * nothing, so it must be authored as unscored. A scored question naming
     * none was authorable and was harmless only by accident, because the
     * totals happen to exclude a notscored result by a different rule.
     */
    public function testAScoredQuestionCannotNameThePolicyThatScoresNothing(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $definition = [
            'prompt' => 'Pick one.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
            'policy' => ['key' => 'none'],
        ];

        // Unscored is the pairing the design states, and it is accepted.
        $survey = $this->addQuestionUseCase()
            ->execute($this->teacher(), $quiz->id, 'single_choice', $definition, 1.0, false);
        self::assertFalse($survey->scored);

        $this->expectException(ValidationException::class);
        $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'single_choice', $definition, 1.0, true);
    }

    /**
     * The policy a question was created under survives an edit that carries
     * it, and the mirror column agrees.
     *
     * The workbench used to rebuild the definition from its form fields
     * alone, so an open-and-save deleted the policy block and the item fell
     * through to the legacy pin: a new multiple-choice item reverted from
     * guess_neutral to plus_minus, which is exactly the formula the design
     * replaced because it pays 0.667 for ticking everything.
     */
    public function testAnEditThatCarriesThePolicyKeepsTheQuestionOnIt(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $definition = [
            'prompt' => 'Pick some.',
            'options' => [
                ['key' => 'a', 'label' => 'A'],
                ['key' => 'b', 'label' => 'B'],
                ['key' => 'c', 'label' => 'C'],
                ['key' => 'd', 'label' => 'D'],
            ],
            'correct' => ['a', 'b', 'c'],
        ];

        $created = $this->addQuestionUseCase()
            ->execute($this->teacher(), $quiz->id, 'multiple_choice', $definition, 3.0);
        self::assertNotNull($created->id);
        self::assertSame('guess_neutral', $created->definition['policy']['key'] ?? null);
        self::assertSame('guess_neutral', $created->scoringPolicy);

        // The workbench sends back the whole stored definition, policy block
        // included, so the edit changes only what the author changed.
        $edited = $this->updateQuestionUseCase()->execute(
            $this->teacher(),
            $created->id,
            $created->definition,
            3.0,
        );

        self::assertSame('guess_neutral', $edited->definition['policy']['key'] ?? null);
        self::assertSame('guess_neutral', $this->questions->byId($created->id)?->scoringPolicy);
    }

    public function testPositionsContinueAfterARemove(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);
        $first = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', $this->trueFalseDefinition());
        self::assertNotNull($first->id);
        $this->removeQuestionUseCase()->execute($this->teacher(), $first->id);

        $second = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', $this->trueFalseDefinition());

        self::assertSame(2, $second->position);
    }
}
