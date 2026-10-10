<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\PresetContext;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionPreset\QuestionPresetInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionVersionAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyResolution;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PrimitiveComposingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ReportingMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;

/**
 * Builds the machine-readable catalogue from the registries.
 *
 * The catalogue is a generated artefact, never a runtime input, and the
 * direction matters. Making one shipped file the thing validateDefinition
 * and the answer-hiding test read would invert ownership in the worst
 * direction: adding a type would edit a core file, the free build would ship
 * every paid schema and secret-key list, and a third-party type would get no
 * schema and, far worse, no coverage from the answer-hiding test.
 *
 * Ownership sits with the provider. This walks the registries and writes
 * down what they say, so the file stays exactly as useful as it was for
 * review, import mapping and documentation, and loses its ability to make a
 * schema change a core release. A checked-in copy that drifts from the
 * registry fails the build.
 */
final class AssessmentCatalogue
{
    public function __construct(private readonly Registries $registries)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'generated_from' => 'the provider registries, not a shipped file',
            'question_types' => $this->questionTypes(),
            'scoring_policies' => $this->scoringPolicies(),
            'interaction_primitives' => $this->primitives(),
            'attempt_policies' => $this->attemptPolicies(),
            'presets' => $this->presets(),
        ];
    }

    /**
     * Stable, human-diffable JSON, so a drift is a readable diff rather than
     * a one-line blob.
     */
    public function toJson(): string
    {
        return json_encode(
            $this->build(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function questionTypes(): array
    {
        $entries = [];

        foreach ($this->registries->questionTypes->all() as $type) {
            $entries[] = $this->questionType($type);
        }

        usort($entries, static function (array $a, array $b): int {
            return [$a['group'], $a['order'], $a['key']] <=> [$b['group'], $b['order'], $b['key']];
        });

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function questionType(QuestionTypeInterface $type): array
    {
        $entry = [
            'key' => $type->key(),
            'group' => $type instanceof ReportingMetadataInterface ? $type->group() : 'selection',
            'order' => $type instanceof ReportingMetadataInterface ? $type->order() : 0,
            'definition_version' => $type->definitionVersion(),
            'supported_definition_versions' => $type instanceof DefinitionVersionAwareInterface
                ? $type->supportedDefinitionVersions()
                : [1],
            'xapi_interaction_type' => $type instanceof ReportingMetadataInterface
                ? $type->xapiInteractionType()
                : 'other',
            'qti_element' => $type instanceof ReportingMetadataInterface ? $type->qtiElement() : null,
        ];

        if ($type instanceof PrimitiveComposingInterface) {
            $entry['primitive'] = $type->interactionPrimitive();
            $entry['surface'] = $type->surface()->value;
        }

        if ($type instanceof ScoringPolicyAwareInterface) {
            $entry['default_policy'] = $type->defaultPolicy();
            $entry['legacy_policy'] = $type->legacyPolicy();
            $entry['supported_policies'] = $type->supportedPolicies();
            // What may actually be authored, computed from the registry rather
            // than read off the type's own list: the shape binding is
            // authoritative and supportedPolicies() only narrows it, so an
            // any-shape decorator is offerable without the type naming it.
            $entry['offerable_policies'] = PolicyResolution::offerable(
                $this->registries->scoringPolicies,
                $type instanceof DefinitionContractInterface ? $type->responseShape() : null,
                $type->supportedPolicies(),
            );
        }

        if ($type instanceof DefinitionContractInterface) {
            $entry['response_shape'] = $type->responseShape()->key();
            $entry['presentable_keys'] = $type->presentableKeys();
            $entry['secret_keys'] = $type->secretKeys();
            $entry['definition_schema'] = $type->definitionSchema();
        }

        return $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scoringPolicies(): array
    {
        $entries = [];

        foreach ($this->registries->scoringPolicies->all() as $policy) {
            $entries[] = $this->scoringPolicy($policy);
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function scoringPolicy(ScoringPolicyInterface $policy): array
    {
        return [
            'key' => $policy->key(),
            'label' => $policy->label(),
            'shapes' => array_map(
                static fn (\Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape $shape): string => $shape->key(),
                $policy->appliesToShapes(),
            ),
            'default_params' => $policy->defaultParams(),
            'params_schema' => $policy->paramsSchema(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function primitives(): array
    {
        return array_map(
            static fn (InteractionPrimitiveInterface $primitive): array => [
                'key' => $primitive->key(),
                'surfaces' => array_map(
                    static fn (\Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface $surface): string => $surface->value,
                    $primitive->surfaces(),
                ),
                'shapes' => array_map(
                    static fn (\Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape $shape): string => $shape->key(),
                    $primitive->shapes(),
                ),
            ],
            $this->registries->interactionPrimitives->all(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attemptPolicies(): array
    {
        return array_map(
            static fn (AttemptPolicyInterface $policy): array => [
                'key' => $policy->key(),
                'reveals_outcome_during_attempt' => $policy->revealsOutcomeDuringAttempt(),
                'tries_per_part' => $policy->triesPerPart(),
            ],
            $this->registries->attemptPolicies->all(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presets(): array
    {
        return array_map(
            static function (QuestionPresetInterface $preset): array {
                // The catalogue is a generated artefact read outside any
                // request, so it takes the neutral context and records the
                // catalogue English rather than a site language.
                $context = PresetContext::neutral();

                return [
                    'preset_key' => $preset->key(),
                    'label' => $preset->label($context),
                    'group' => $preset->group($context),
                    'base_type_key' => $preset->baseTypeKey($context),
                    'default_policy' => $preset->defaultPolicy($context),
                    'definition_skeleton' => $preset->definitionSkeleton($context),
                    'source_aliases' => $preset->sourceAliases($context),
                    'help_url' => $preset->helpUrl($context),
                ];
            },
            $this->registries->questionPresets->all(),
        );
    }
}
