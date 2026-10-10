<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * The editorial-responsibility record, and the half of the provenance pair that
 * outlives the other.
 *
 * ai_runs is the ledger and is purged on the policy's retention clock.
 * ai_artefact_provenance is the record that survives, is excluded from the
 * purge, and denormalises the task, the task version, the prompt reference, the
 * model, the inputs digest, the disclosure tier and the edit distance ratio from
 * the run and the draft. An editorial-responsibility record whose task and model
 * vanished after a hundred and eighty days is not a record.
 *
 * It is polymorphic in its owner exactly as availability_rule_refs is, so no
 * context table gains AI columns and the Curriculum, Assessment and Credentials
 * contexts stay unpolluted. A nullable ai_run_id on questions, lessons, rubrics
 * and every future content table would be six migrations now, would still miss a
 * third-party package's table, and would answer "everything AI touched in this
 * course" with six unions.
 *
 * The disclosure tier is DERIVED and never declared. A self-declared label is a
 * label nobody maintains.
 */
final class AiArtefactProvenance
{
    /**
     * The 1EdTech five-tier scale, in order of how much of the artefact the
     * machine wrote. The value is computed from the edit distance between the
     * generated payload and the accepted one, combined with the task's own risk
     * tier, and it is stored so it can later be carried through QTI extensible
     * metadata, Common Cartridge package metadata and Caliper event extensions.
     */
    public const TIER_HUMAN_CREATED_ASSISTED = 'human_created_genai_assisted';
    public const TIER_HUMAN_LED_ENHANCED = 'human_led_genai_enhanced';
    public const TIER_CO_CREATED_HUMAN_GUIDED = 'genai_co_created_human_guided';
    public const TIER_DOMINANT_HUMAN_SUPERVISED = 'genai_dominant_human_supervised';
    public const TIER_FULLY_GENERATED = 'fully_ai_generated';

    public function __construct(
        public readonly int $id,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly int $runId,
        public readonly ?int $draftId,
        public readonly string $taskKey,
        public readonly int $taskVersion,
        public readonly ?string $promptRef,
        public readonly ?string $model,
        public readonly string $inputsDigest,
        public readonly string $disclosureTier,
        public readonly ?float $editDistanceRatio,
        public readonly bool $humanEdited,
        public readonly int $acceptedBy,
        public readonly \DateTimeImmutable $acceptedAt,
        public readonly ?\DateTimeImmutable $supersededAt = null,
    ) {
    }

    /**
     * The tier a given edit distance earns.
     *
     * The ratio is the proportion of the accepted payload that differs from the
     * generated one, so 0.0 is a rubber stamp and 1.0 is a rewrite. The bands
     * are the five published tiers read in that direction, and the value is
     * derived here rather than in a screen so the compliance report and the
     * review panel cannot disagree about what a tier means.
     */
    public static function tierFor(?float $editDistanceRatio): string
    {
        if ($editDistanceRatio === null) {
            // Not measurable is not the same as unedited, and calling it a
            // rubber stamp would libel a reviewer the product could not observe.
            return self::TIER_DOMINANT_HUMAN_SUPERVISED;
        }

        return match (true) {
            $editDistanceRatio <= 0.0 => self::TIER_FULLY_GENERATED,
            $editDistanceRatio < 0.15 => self::TIER_DOMINANT_HUMAN_SUPERVISED,
            $editDistanceRatio < 0.4 => self::TIER_CO_CREATED_HUMAN_GUIDED,
            $editDistanceRatio < 0.75 => self::TIER_HUMAN_LED_ENHANCED,
            default => self::TIER_HUMAN_CREATED_ASSISTED,
        };
    }

    /**
     * How far an accepted payload moved from the generated one, as a ratio in
     * [0, 1].
     *
     * A normalised Levenshtein over the canonical JSON encoding of the two
     * payloads. The encoding is canonical (sorted keys) so a reordering by a
     * form round trip is not counted as an edit, which would otherwise report
     * every reviewer as diligent for pressing save.
     *
     * @param array<string, mixed> $generated
     * @param array<string, mixed> $accepted
     */
    public static function editDistanceRatio(array $generated, array $accepted): float
    {
        $before = self::canonical($generated);
        $after = self::canonical($accepted);

        if ($before === $after) {
            return 0.0;
        }

        $longest = max(\strlen($before), \strlen($after));

        if ($longest === 0) {
            return 0.0;
        }

        // levenshtein() is bounded at 255 bytes per argument, and a lesson body
        // is longer than that, so long payloads fall back to a comparison of
        // their canonical digests plus a length ratio. That is coarser and it is
        // said out loud rather than silently returning 1.0 for every long draft.
        if (\strlen($before) > 255 || \strlen($after) > 255) {
            $shortest = min(\strlen($before), \strlen($after));

            return round(1.0 - ($shortest / $longest), 4);
        }

        return round(levenshtein($before, $after) / $longest, 4);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function canonical(array $payload): string
    {
        self::sortDeep($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function sortDeep(array &$payload): void
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (\is_array($value)) {
                /** @var array<string, mixed> $value */
                self::sortDeep($value);
            }
        }

        unset($value);
    }
}
