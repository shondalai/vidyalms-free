<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityReason;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
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
 * Another item's state.
 *
 * Matching is deliberately asymmetric, and the asymmetry is correct and
 * non-obvious: complete also accepts complete_pass, incomplete also accepts
 * complete_fail, and every other value requires an exact match.
 *
 * An id of -1 means the previous item with completion, which survives
 * reordering, duplication and course copy where an absolute id does not. A
 * -1 in the first position of a course resolves to nothing and is treated as
 * a missing target, which the save-time validator warns about.
 *
 * A target that cannot be resolved after a course copy evaluates to blocked
 * with a target-missing reason and never to available. An archived target is
 * a missing target.
 */
final class CompletionCondition implements
    AvailabilityConditionInterface,
    ConditionMetadataInterface,
    BatchEvaluatesItems,
    DeclaresReferences,
    RequiresFacts,
    SupportsPayloadVersions,
    ValidatesPayload
{
    use EvaluatesManyByEvaluate;

    public const KEY = 'completion';

    public const STATE_INCOMPLETE = 'incomplete';
    public const STATE_COMPLETE = 'complete';
    public const STATE_COMPLETE_PASS = 'complete_pass';
    public const STATE_COMPLETE_FAIL = 'complete_fail';

    /** The previous item with completion, which is what -1 means. */
    public const TARGET_PREVIOUS = -1;

    /** @var list<string> */
    private const TARGET_KINDS = [
        GateSubject::TYPE_LESSON,
        GateSubject::TYPE_MODULE,
        GateSubject::TYPE_QUIZ,
        GateSubject::TYPE_ASSIGNMENT,
    ];

    /** @var list<string> */
    private const STATES = [
        self::STATE_INCOMPLETE,
        self::STATE_COMPLETE,
        self::STATE_COMPLETE_PASS,
        self::STATE_COMPLETE_FAIL,
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.availability.condition.completion';
    }

    public function group(): string
    {
        return ConditionMetadataInterface::GROUP_PROGRESS;
    }

    public function order(): int
    {
        return 20;
    }

    public function advisory(): bool
    {
        return false;
    }

    /**
     * Automation is declared, with the reservation stated rather than left
     * implicit. In a content gate this leaf asks "may this learner see the
     * next item"; in a scheduled rule it asks "has this learner finished the
     * thing the rule is about", which is the same read against a different
     * question and is exactly what an automation acting on completion needs.
     * What it is not is an event: a rule that must fire at the instant of
     * completion uses the completion trigger, and this leaf is the standing
     * test a sweep evaluates.
     *
     * Enrolment gating stays excluded for the reason it always was: a
     * prerequisite belongs to the enrolment rule, not to a leaf that reads
     * progress on an enrolment that does not exist yet.
     */
    /**
     * Redemption gating is excluded, deliberately.
     *
     * It reads an enrolment's completed lessons, and a cart has no
     * enrolment: the buyer may not be enrolled in anything the coupon
     * applies to, which is the normal case for a first purchase. A leaf
     * that resolved to "nothing completed" there would make a
     * completion-based coupon restriction silently always true.
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
        return [AvailabilityContext::FACT_PROGRESS];
    }

    public function upgrade(array $payload, int $from, UpgradeContext $context): array
    {
        return $payload;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['target', 'state'],
            'properties' => [
                'target' => [
                    'type' => 'object',
                    'required' => ['kind', 'id'],
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => ['lesson', 'module', 'quiz', 'assignment']],
                        'id' => ['type' => 'integer'],
                    ],
                ],
                'state' => ['type' => 'string', 'enum' => self::STATES],
            ],
            'additionalProperties' => false,
        ];
    }

    public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
    {
        $facts = ProgressFacts::of($context);
        $wanted = $this->stateOf($payload);
        $target = $this->resolveTarget($payload, $facts, $context->subject);

        if ($target === null) {
            return AvailabilityOutcome::blocked('availability.completion.target_missing', [
                'kind' => $this->kindOf($payload),
                'state' => $wanted,
            ]);
        }

        $actual = $facts->stateOf($target);
        $satisfied = $this->matches($wanted, $actual);
        $parameters = [
            'title' => $facts->titleOf($target),
            'kind' => $target->type,
            'id' => $target->id,
            'state' => $wanted,
        ];

        if ($satisfied !== $negated) {
            return AvailabilityOutcome::available();
        }

        return AvailabilityOutcome::blocked(
            $negated ? 'availability.completion.forbidden' : 'availability.completion.required',
            $parameters,
        );
    }

    public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
    {
        return new AvailabilityReason(
            $negated ? 'availability.completion.forbidden' : 'availability.completion.required',
            [
                'kind' => $this->kindOf($payload),
                'id' => $this->idOf($payload),
                'state' => $this->stateOf($payload),
            ],
        );
    }

    public function dependsOn(array $payload): array
    {
        $id = $this->idOf($payload);

        if ($id === null || $id === self::TARGET_PREVIOUS) {
            return [];
        }

        return [new GateSubject($this->kindOf($payload), $id)];
    }

    public function remapReferences(array $payload, array $map): array
    {
        $id = $this->idOf($payload);
        $kind = $this->kindOf($payload);

        if ($id === null || $id === self::TARGET_PREVIOUS) {
            return $payload;
        }

        $replacement = $map[$kind][$id] ?? null;

        if ($replacement === null) {
            return $payload;
        }

        $payload['target'] = ['kind' => $kind, 'id' => $replacement];

        return $payload;
    }

    public function validatePayload(array $payload, PayloadValidationContext $context): PayloadValidationResult
    {
        $kind = $this->kindOf($payload);
        $id = $this->idOf($payload);
        $state = $this->stateOf($payload);

        if (!\in_array($kind, self::TARGET_KINDS, true)) {
            return PayloadValidationResult::error('availability.completion.unknown_kind', ['kind' => $kind]);
        }

        if ($id === null || ($id < 1 && $id !== self::TARGET_PREVIOUS)) {
            return PayloadValidationResult::error('availability.completion.target_required');
        }

        if (!\in_array($state, self::STATES, true)) {
            return PayloadValidationResult::error('availability.completion.unknown_state', ['state' => $state]);
        }

        if ($state !== self::STATE_COMPLETE_PASS && $state !== self::STATE_COMPLETE_FAIL) {
            return PayloadValidationResult::valid();
        }

        // A pass or fail state against a target that carries no pass mark can
        // never be satisfied. The picker hides the option, and this is the
        // check: a rule arriving through the API, an importer or a course
        // copy would otherwise store a leaf that sits in the denominator
        // forever.
        if ($id === self::TARGET_PREVIOUS) {
            return PayloadValidationResult::valid();
        }

        if (!$this->targetCarriesPassMark($kind, $id, $context)) {
            return PayloadValidationResult::error('availability.completion.no_pass_mark', [
                'kind' => $kind,
                'id' => $id,
                'state' => $state,
            ]);
        }

        return PayloadValidationResult::valid();
    }

    private function targetCarriesPassMark(string $kind, int $id, PayloadValidationContext $context): bool
    {
        if ($kind === GateSubject::TYPE_QUIZ) {
            $row = $context->database->selectOne(
                Query::from('quizzes')->select(['pass_mark'])->where('id', '=', $id),
            );

            return $row !== null && (int) ($row['pass_mark'] ?? 0) > 0;
        }

        if ($kind === GateSubject::TYPE_ASSIGNMENT) {
            $row = $context->database->selectOne(
                Query::from('assignments')->select(['id'])->where('id', '=', $id),
            );

            return $row !== null;
        }

        if ($kind !== GateSubject::TYPE_LESSON) {
            return false;
        }

        // A lesson carries a pass mark only through the quiz inside it.
        $quiz = $context->database->selectOne(
            Query::from('quizzes')->select(['pass_mark'])->where('lesson_id', '=', $id),
        );

        if ($quiz !== null && (int) ($quiz['pass_mark'] ?? 0) > 0) {
            return true;
        }
        $assignment = $context->database->selectOne(
            Query::from('assignments')->select(['pass_percentage'])->where('lesson_id', '=', $id),
        );
        return $assignment !== null && $assignment['pass_percentage'] !== null;
    }

    /**
     * The asymmetry, in one place: complete accepts complete_pass and
     * incomplete accepts complete_fail; everything else is exact.
     */
    private function matches(string $wanted, string $actual): bool
    {
        if ($wanted === self::STATE_COMPLETE) {
            return $actual === self::STATE_COMPLETE || $actual === self::STATE_COMPLETE_PASS;
        }

        if ($wanted === self::STATE_INCOMPLETE) {
            return $actual === self::STATE_INCOMPLETE || $actual === self::STATE_COMPLETE_FAIL;
        }

        return $wanted === $actual;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveTarget(array $payload, ProgressFacts $facts, ?GateSubject $subject): ?GateSubject
    {
        $kind = $this->kindOf($payload);
        $id = $this->idOf($payload);

        if ($id === null) {
            return null;
        }

        if ($id !== self::TARGET_PREVIOUS) {
            return $facts->knows(new GateSubject($kind, $id)) ? new GateSubject($kind, $id) : null;
        }

        return $facts->previousOf($subject);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function kindOf(array $payload): string
    {
        $target = $payload['target'] ?? null;
        $kind = \is_array($target) ? ($target['kind'] ?? null) : null;

        return \is_string($kind) ? $kind : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function idOf(array $payload): ?int
    {
        $target = $payload['target'] ?? null;
        $id = \is_array($target) ? ($target['id'] ?? null) : null;

        return \is_int($id) || (\is_string($id) && preg_match('/^-?\d+$/', $id) === 1) ? (int) $id : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stateOf(array $payload): string
    {
        $state = $payload['state'] ?? null;

        return \is_string($state) ? $state : self::STATE_COMPLETE;
    }
}
