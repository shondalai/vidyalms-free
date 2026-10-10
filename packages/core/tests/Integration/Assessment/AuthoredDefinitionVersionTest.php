<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\VersionedProbeType;

/**
 * A caller may state the shape its definition was written at.
 *
 * The repository stamps every new row with the version the resolved type
 * writes today, which is right for an authoring screen and wrong for anything
 * that hands over a body it wrote earlier: a generated draft, an importer, a
 * restored export. That body is stored unchanged under the current version,
 * so the read path sees nothing to upgrade, and a type whose upgrade renames
 * a field hands the old spelling to a grader looking for the new one. Every
 * answer is marked wrong and nothing anywhere reports it.
 */
final class AuthoredDefinitionVersionTest extends AssessmentIntegrationTestCase
{
    private int $quizId;

    protected function setUp(): void
    {
        parent::setUp();

        $courseId = $this->createCourse();
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);
        $this->quizId = $quiz->id;

        // Version 2 keeps its accepted answers under "matchers"; version 1
        // kept them under "accepted" and the upgrade renames the field.
        $this->questionTypes->register(VersionedProbeType::v2());
    }

    public function testABodyDeclaredAtAnOlderShapeIsUpgradedBeforeItIsStored(): void
    {
        $question = $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $this->quizId,
            VersionedProbeType::KEY,
            ['prompt' => 'Pick.', 'accepted' => ['blue']],
            definitionVersion: 1,
        );
        self::assertNotNull($question->id);

        self::assertSame(2, $this->storedVersion($question->id));

        $read = $this->questions->byId($question->id);
        self::assertNotNull($read);
        self::assertSame(['blue'], $read->definition['matchers'] ?? null);
        self::assertArrayNotHasKey('accepted', $read->definition);
    }

    /**
     * The control, and the defect the parameter closes: the same body handed
     * over with no version claim is stored verbatim under version 2, so the
     * grader reads "matchers", finds nothing, and marks every answer wrong.
     */
    public function testTheSameBodyWithNoVersionClaimKeepsTheOldSpellingUnderTheNewVersion(): void
    {
        $question = $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $this->quizId,
            VersionedProbeType::KEY,
            ['prompt' => 'Pick.', 'accepted' => ['blue']],
        );
        self::assertNotNull($question->id);

        self::assertSame(2, $this->storedVersion($question->id));

        $read = $this->questions->byId($question->id);
        self::assertNotNull($read);
        self::assertArrayNotHasKey('matchers', $read->definition);
    }

    public function testABodyDeclaredAtTheCurrentShapeIsStoredUntouched(): void
    {
        $question = $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $this->quizId,
            VersionedProbeType::KEY,
            ['prompt' => 'Pick.', 'matchers' => ['blue']],
            definitionVersion: 2,
        );
        self::assertNotNull($question->id);

        $read = $this->questions->byId($question->id);
        self::assertNotNull($read);
        self::assertSame(['blue'], $read->definition['matchers'] ?? null);
        self::assertSame(2, $this->storedVersion($question->id));
    }

    public function testAVersionAboveTheResolvedTypesOwnIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $this->quizId,
            VersionedProbeType::KEY,
            ['prompt' => 'Pick.', 'matchers' => ['blue']],
            definitionVersion: 3,
        );
    }

    public function testAVersionBelowOneIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $this->quizId,
            VersionedProbeType::KEY,
            ['prompt' => 'Pick.', 'matchers' => ['blue']],
            definitionVersion: 0,
        );
    }

    private function storedVersion(int $questionId): int
    {
        $row = $this->env->database->selectOne(Query::from('questions')->where('id', '=', $questionId));
        self::assertNotNull($row);

        return (int) $row['definition_version'];
    }
}
