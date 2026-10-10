<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityReason;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\BatchEvaluatesItems;
use Shondalai\VidyaLMS\Core\Registry\Availability\ConditionMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\DeclaresReferences;
use Shondalai\VidyaLMS\Core\Registry\Availability\PayloadValidationResult;
use Shondalai\VidyaLMS\Core\Registry\Availability\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Availability\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\DescriptionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * A percentage band over another item's grade.
 *
 * The minimum is inclusive and the maximum is exclusive, which is the single
 * most reported surprise in the platform this rule is taken from: a maximum
 * of 7 means a learner scoring exactly 7 does not see the item. The authoring
 * form prints that inline rather than leaving it to a docblock.
 *
 * No grade recorded is treated as not satisfied rather than as unknown, and
 * never as a score of zero, which a band with a minimum of zero would
 * otherwise admit.
 *
 * A downward regrade retracts access. That is correct and surprising, and the
 * remedy is an explicit, auditable grant rather than a condition that latches
 * and cannot be reset.
 */
final class ScoreCondition implements
    AvailabilityConditionInterface,
    ConditionMetadataInterface,
    BatchEvaluatesItems,
    DeclaresReferences,
    RequiresFacts,
    SupportsPayloadVersions,
    ValidatesPayload
{
    use EvaluatesManyByEvaluate;

    public const KEY = 'score';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.availability.condition.score';
    }

    public function group(): string
    {
        return ConditionMetadataInterface::GROUP_PROGRESS;
    }

    public function order(): int
    {
        return 30;
    }

    public function advisory(): bool
    {
        return false;
    }

    /**
     * Automation is declared: a band over a recorded grade is a standing
     * fact about a learner, and a rule that acts only on learners who scored
     * below a threshold is the plainest automation there is. The band reads
     * a stored grade rather than a live evaluation, so a scheduler asking it
     * gets the same answer a page would.
     *
     * Enrolment gating stays excluded because there is no attempt to read
     * before the learner is enrolled.
     */
    /**
     * Redemption gating is excluded, deliberately.
     *
     * A score is an attempt's, and an attempt belongs to an enrolment a
     * cart does not have. The same silent-always-true failure the
     * completion condition names applies here.
     */
    public function applicability(): array
    {
        return [
            AvailabilitySurface::ContentGating,
            AvailabilitySurface::NotificationRule,
            AvailabilitySurface::AwardCriteria,
            AvailabilitySurface::Automation,
        ];
    }

    public function currentShape(): int
    {
        return 1;
    }

    public function supportedPayloadVersions(): array
    {
        return [1];
    }

    public function requiredFacts(): array
    {
        return [AvailabilityContext::FACT_GRADES];
    }

    public function upgrade(array $payload, int $from, UpgradeContext $context): array
    {
        return $payload;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['target'],
            'properties' => [
                'target' => [
                    'type' => 'object',
                    'required' => ['kind', 'id'],
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => ['lesson', 'quiz', 'course']],
                        'id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                ],
                'min' => ['type' => ['number', 'null']],
                'max' => ['type' => ['number', 'null'], 'description' => 'Exclusive.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
    {
        $facts = GradeFacts::of($context);
        $target = $this->targetOf($payload);

        if ($target === null) {
            return AvailabilityOutcome::blocked('availability.score.target_missing');
        }

        $min = $this->boundOf($payload, 'min');
        $max = $this->boundOf($payload, 'max');
        $percentage = $facts->percentageOf($target);

        $satisfied = $percentage !== null
            && ($min === null || $percentage >= $min)
            && ($max === null || $percentage < $max);

        if ($satisfied !== $negated) {
            return AvailabilityOutcome::available();
        }

        $parameters = [
            'title' => $facts->titleOf($target),
            'kind' => $target->type,
            'id' => $target->id,
            'min' => $min,
            'max' => $max,
        ];

        return AvailabilityOutcome::blocked(
            $negated ? 'availability.score.outside' : $this->reasonKeyFor($min, $max),
            $parameters,
        );
    }

    public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
    {
        $target = $this->targetOf($payload);
        $min = $this->boundOf($payload, 'min');
        $max = $this->boundOf($payload, 'max');

        return new AvailabilityReason(
            $negated ? 'availability.score.outside' : $this->reasonKeyFor($min, $max),
            [
                'kind' => $target?->type,
                'id' => $target?->id,
                'min' => $min,
                'max' => $max,
            ],
        );
    }

    public function dependsOn(array $payload): array
    {
        $target = $this->targetOf($payload);

        return $target === null ? [] : [$target];
    }

    public function remapReferences(array $payload, array $map): array
    {
        $target = $this->targetOf($payload);

        if ($target === null) {
            return $payload;
        }

        $replacement = $map[$target->type][$target->id] ?? null;

        if ($replacement === null) {
            return $payload;
        }

        $payload['target'] = ['kind' => $target->type, 'id' => $replacement];

        return $payload;
    }

    public function validatePayload(array $payload, PayloadValidationContext $context): PayloadValidationResult
    {
        if ($this->targetOf($payload) === null) {
            return PayloadValidationResult::error('availability.score.target_required');
        }

        $min = $this->boundOf($payload, 'min');
        $max = $this->boundOf($payload, 'max');

        if ($min === null && $max === null) {
            return PayloadValidationResult::error('availability.score.range_required');
        }

        if ($min !== null && $max !== null && $min > $max) {
            return PayloadValidationResult::error('availability.score.range_impossible', [
                'min' => $min,
                'max' => $max,
            ]);
        }

        // A warning and not a refusal: a course total can exceed a hundred
        // with bonus points, so an author who means it is not stopped.
        if ($max !== null && $max >= 100.0) {
            return PayloadValidationResult::warning('availability.score.max_above_attainable', ['max' => $max]);
        }

        return PayloadValidationResult::valid();
    }

    private function reasonKeyFor(?float $min, ?float $max): string
    {
        if ($min !== null && $max !== null) {
            return 'availability.score.band';
        }

        return $min !== null ? 'availability.score.min' : 'availability.score.max';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function targetOf(array $payload): ?GateSubject
    {
        $target = $payload['target'] ?? null;

        if (!\is_array($target)) {
            return null;
        }

        $kind = $target['kind'] ?? null;
        $id = $target['id'] ?? null;

        if (!\is_string($kind) || !\in_array($kind, [GateSubject::TYPE_LESSON, GateSubject::TYPE_QUIZ, GateSubject::TYPE_COURSE], true)) {
            return null;
        }

        if (!\is_int($id) && !(\is_string($id) && preg_match('/^\d+$/', $id) === 1)) {
            return null;
        }

        return (int) $id > 0 ? new GateSubject($kind, (int) $id) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function boundOf(array $payload, string $key): ?float
    {
        $value = $payload[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }
}
