<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\GetQuizSettingsSchema;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\ThrowawayMultiTryBehaviour;

/**
 * The try penalty under the registry-miss contract.
 *
 * Every field whose value is a provider key has to answer the same three
 * things when the provider is gone: available false, a reason naming the
 * missing package, and the stored value still shown so the author can see
 * what the quiz was set to. The penalty field answered none of them: an
 * unresolvable behaviour read exactly like no behaviour at all, so a quiz
 * stored on a paid multi-try behaviour reported its penalty as live and
 * editable the moment the package came off, while the behaviour field
 * directly above it named the miss.
 */
final class TryPenaltyRegistryMissTest extends AssessmentIntegrationTestCase
{
    private const PACKAGE = 'x-acme-behaviours';

    private int $quizId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The behaviour is registered while the quiz is authored, exactly as
        // it would be while the package is installed, and the ledger records
        // who owns the key exactly as a package's own attach does.
        $this->attemptPolicies->register(new ThrowawayMultiTryBehaviour());
        $this->featureProviders->record(
            'attempt_policy',
            ThrowawayMultiTryBehaviour::KEY,
            self::PACKAGE,
        );

        $courseId = $this->createCourse();
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(
            behaviour: ThrowawayMultiTryBehaviour::KEY,
            tryPenalty: 0.25,
        ));
        self::assertNotNull($quiz->id);
        $this->quizId = $quiz->id;
    }

    /**
     * The catalogue's copy carries no placeholders, so the shared stub
     * translator drops the parameters and the assertion could not see which
     * key or which package was named. This one renders them, which is the
     * whole of what the reason has to prove.
     */
    private function translator(): TranslatorPort
    {
        return new class implements TranslatorPort {
            public function translate(string $key, array $parameters = []): string
            {
                $rendered = [];

                foreach ($parameters as $name => $value) {
                    $rendered[] = $name . '=' . (string) $value;
                }

                return $rendered === [] ? $key : $key . '(' . implode(',', $rendered) . ')';
            }
        };
    }

    private function schemaOver(AttemptPolicyRegistry $behaviours): GetQuizSettingsSchema
    {
        return new GetQuizSettingsSchema(
            $this->quizzes,
            $this->access,
            $behaviours,
            $this->aggregationMethods,
            $this->negativeMarking,
            $this->translator(),
            $this->featureProviders,
        );
    }

    /**
     * There is no unregister and there will not be one, so a removed package
     * is a registry that never had the key, which is what the site boots
     * after the uninstall.
     */
    private function withoutThePackage(): AttemptPolicyRegistry
    {
        $registry = new AttemptPolicyRegistry();

        foreach ($this->attemptPolicies->all() as $behaviour) {
            if ($behaviour->key() !== ThrowawayMultiTryBehaviour::KEY) {
                $registry->register($behaviour);
            }
        }

        return $registry;
    }

    /**
     * @return array<string, mixed>
     */
    private function penaltyField(GetQuizSettingsSchema $schema): array
    {
        $document = $schema->execute($this->teacher(), $this->quizId);

        foreach ($document['groups'] as $group) {
            if ($group['key'] !== 'grading') {
                continue;
            }

            self::assertIsArray($group['fields']);

            foreach ($group['fields'] as $field) {
                self::assertIsArray($field);

                if ($field['key'] === 'try_penalty') {
                    return $field;
                }
            }
        }

        self::fail('The settings schema has no try_penalty field.');
    }

    public function testTheBehaviourThatHonoursThePenaltyLeavesTheFieldAvailable(): void
    {
        $field = $this->penaltyField($this->schemaOver($this->attemptPolicies));

        self::assertTrue($field['available']);
        self::assertNull($field['unavailable_reason']);
        self::assertSame(0.25, $field['current']);
    }

    public function testRemovingThePackageMakesThePenaltyUnavailableAndNamesIt(): void
    {
        $field = $this->penaltyField($this->schemaOver($this->withoutThePackage()));

        self::assertFalse($field['available'], 'no installed behaviour can apply the stored penalty');
        self::assertIsString($field['unavailable_reason']);
        self::assertStringContainsString(ThrowawayMultiTryBehaviour::KEY, $field['unavailable_reason']);
        self::assertStringContainsString(self::PACKAGE, $field['unavailable_reason']);
        self::assertSame(0.25, $field['current'], 'the author still sees what the quiz was set to');
    }

    /**
     * The two rows must agree. The behaviour field named the miss all along;
     * the defect was the penalty beside it saying the opposite.
     */
    public function testTheBehaviourFieldAndThePenaltyFieldTellTheSameStory(): void
    {
        $document = $this->schemaOver($this->withoutThePackage())->execute($this->teacher(), $this->quizId);
        $behaviour = null;
        $penalty = null;

        foreach ($document['groups'] as $group) {
            self::assertIsArray($group['fields']);

            foreach ($group['fields'] as $field) {
                self::assertIsArray($field);

                if ($field['key'] === 'behaviour') {
                    $behaviour = $field;
                }

                if ($field['key'] === 'try_penalty') {
                    $penalty = $field;
                }
            }
        }

        self::assertIsArray($behaviour);
        self::assertIsArray($penalty);
        self::assertFalse($behaviour['available']);
        self::assertFalse($penalty['available']);
        self::assertSame($behaviour['unavailable_reason'], $penalty['unavailable_reason']);
    }

    /**
     * A quiz that names no behaviour runs the free default, and the free
     * default pins the penalty to zero. That is a setting the resolved
     * behaviour overrides rather than one no package provides, so it reports
     * the ignored reason and not the missing-package one.
     */
    public function testAQuizWithNoStoredBehaviourReportsTheFreeDefaultAsIgnoringThePenalty(): void
    {
        $courseId = $this->createCourse('course-default-behaviour');
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(tryPenalty: 0.4));
        self::assertNotNull($quiz->id);
        $this->quizId = $quiz->id;

        $field = $this->penaltyField($this->schemaOver($this->attemptPolicies));

        self::assertFalse($field['available']);
        self::assertIsString($field['unavailable_reason']);
        self::assertStringContainsString(
            AttemptPolicyRegistry::DEFERRED_FEEDBACK,
            $field['unavailable_reason'],
        );
        self::assertSame(0.4, $field['current']);
    }

    /**
     * A penalty of zero is honoured by every behaviour there is, so nothing
     * is reported unavailable for a quiz that never set one.
     */
    public function testAQuizWithNoPenaltyLeavesTheFieldAvailable(): void
    {
        $courseId = $this->createCourse('course-no-penalty');
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);
        $this->quizId = $quiz->id;

        $field = $this->penaltyField($this->schemaOver($this->attemptPolicies));

        self::assertTrue($field['available']);
        self::assertNull($field['unavailable_reason']);
    }
}
