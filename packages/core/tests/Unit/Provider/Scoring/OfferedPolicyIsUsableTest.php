<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Scoring;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Boot\AssessmentProviders;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ContextAwareGrading;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyResolution;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

/**
 * A policy a type offers must actually mark that type.
 *
 * multiple_choice offered map_response while no free code path could build a
 * MappingSpec, so choosing "Weighted per option" produced an item that graded
 * NotScored for every learner. That is worse than a zero: a notscored result
 * is excluded from both sums, so the item disappeared from the quiz total
 * rather than being marked wrong, requiresManualGrading stayed false, and the
 * attempt still reached "graded". No error at authoring time and no
 * pending-grade signal afterwards.
 *
 * The invariant that catches it generically: for every registered type, every
 * key it offers grades its own example definition without reporting a
 * configuration reason.
 */
final class OfferedPolicyIsUsableTest extends TestCase
{
    /**
     * Policies that legitimately decline to produce a mark: the item is
     * waiting for a human, or is authored not to count.
     */
    private const NOT_A_FORMULA = ['external', PolicyResolution::SCORES_NOTHING];

    public function testEveryOfferedPolicyMarksItsOwnTypesExample(): void
    {
        $registries = new Registries();
        AssessmentProviders::registerInto($registries, new StubTranslator());

        $checked = 0;

        foreach ($registries->questionTypes->all() as $type) {
            if (!$type instanceof ScoringPolicyAwareInterface || !$type instanceof DefinitionContractInterface) {
                continue;
            }

            self::assertInstanceOf(ContextAwareGrading::class, $type);

            $offerable = PolicyResolution::offerable(
                $registries->scoringPolicies,
                $type->responseShape(),
                $type->supportedPolicies(),
            );

            foreach ($offerable as $key) {
                if (\in_array($key, self::NOT_A_FORMULA, true)) {
                    continue;
                }

                $definition = $type->exampleDefinition();
                $definition['policy'] = ['key' => $key];

                $result = $type->gradeInContext(
                    $definition,
                    null,
                    GradingContext::create(policies: $registries->scoringPolicies),
                );

                // A notscored result is excluded from both sums, so an item
                // that reaches it for a configuration reason vanishes from
                // the quiz total for every learner rather than being marked
                // wrong. An unanswered item must still be a plain zero.
                self::assertSame(ScoreStatus::Scored, $result->scoreStatus, sprintf(
                    '%s offers %s, but marking its own example is not scored (%s): the policy is '
                    . 'offered without the definition field it needs, so the item would silently '
                    . 'stop counting instead of being marked wrong.',
                    $type->key(),
                    $key,
                    (string) ($result->scoringParams['reason'] ?? 'no reason given'),
                ));

                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked, 'the walk must actually grade something');
    }
}
