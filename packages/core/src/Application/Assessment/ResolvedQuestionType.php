<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoringIntent;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiVocabulary;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PresentationContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ContextAwareGrading;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\AnswerValidatingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ShapeForDefinition;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ContextAwarePresenting;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ManualGradeAdjustingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ManualGradingDecidingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PartScoringInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionPart;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ReportingMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoredReportingInterface;

/**
 * One question's provider, or the reason there is not one.
 *
 * A registry miss is a normal path, not an exception. Removing Pro must not
 * throw out of submission, out of the learner attempt view, out of manual
 * grading or out of a save, and it must not quietly drop a pending essay out
 * of the instructor's queue either, which is worse because there is no error
 * anywhere and an attempt with an ungraded essay reads as fully graded.
 *
 * Every consumer goes through this object, so the miss behaves the same way
 * in all seven places: a placeholder presentation, raw 0 with score status
 * notscored and an explanatory row, and an item that still counts as pending
 * when a human was meant to mark it.
 */
final class ResolvedQuestionType
{
    public const MISS_NONE = '';
    public const MISS_UNKNOWN_TYPE = 'unknown_type';
    public const MISS_UNSUPPORTED_VERSION = 'unsupported_definition_version';
    public const MISS_DEFINITION_LOST = 'frozen_definition_unavailable';

    private function __construct(
        public readonly string $typeKey,
        public readonly ?QuestionTypeInterface $provider,
        public readonly string $miss,
        public readonly ?int $storedVersion = null,
        private readonly ?TranslatorPort $translator = null,
        private readonly ?string $package = null,
    ) {
    }

    public static function available(string $typeKey, QuestionTypeInterface $provider): self
    {
        return new self($typeKey, $provider, self::MISS_NONE);
    }

    public static function unknownType(string $typeKey): self
    {
        return new self($typeKey, null, self::MISS_UNKNOWN_TYPE);
    }

    /**
     * The same resolution, told which package owns the key and how to say so
     * in the site's language.
     *
     * The explanatory grade row goes into attempt_grades.feedback and is read
     * by learners and graders, so it is a translated string with parameters
     * rather than an English literal built in a use case, and it names the
     * missing package rather than only the type key, which is what the ledger
     * knows and nothing used to read.
     */
    public function describedBy(?TranslatorPort $translator, ?string $package): self
    {
        return new self($this->typeKey, $this->provider, $this->miss, $this->storedVersion, $translator, $package);
    }

    /**
     * A resolvable key carrying a definition version the provider does not
     * support. This is the case the four call sites cannot see, and it is
     * the one that would bite hardest: Pro decorates the free six through
     * replace(), so removing Pro re-registers the free provider under the
     * same key and get('fill_blank') resolves. No exception is ever thrown
     * for the six most-used keys, and the free provider then awards zero for
     * every blank of a Pro-shaped definition with a normal score status and
     * no warning. Data survives; marks do not.
     */
    public static function unsupportedVersion(string $typeKey, QuestionTypeInterface $provider, int $storedVersion): self
    {
        return new self($typeKey, $provider, self::MISS_UNSUPPORTED_VERSION, $storedVersion);
    }

    /**
     * The manifest names a frozen definition the content-addressed store no
     * longer holds. The provider may be perfectly installed; what is gone is
     * the thing the attempt was graded from, and the live questions row is
     * not a substitute for it, because the whole reason the store exists is
     * that the live row can have been rewritten since.
     */
    public static function definitionLost(string $typeKey, ?QuestionTypeInterface $provider = null): self
    {
        return new self($typeKey, $provider, self::MISS_DEFINITION_LOST);
    }

    public function isAvailable(): bool
    {
        return $this->miss === self::MISS_NONE && $this->provider !== null;
    }

    /**
     * The learner-facing shape, or the placeholder when there is no usable
     * provider. The placeholder is keyed on the response shape, which core
     * knows because it is a real column, so a missing associate renderer
     * still shows a readable list of the learner's chosen pairs rather than
     * a blunt unsupported panel.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function present(array $definition, PresentationContext $context, ?string $responseShape = null): array
    {
        $provider = $this->provider;

        if (!$this->isAvailable() || $provider === null) {
            return $this->placeholderShape($definition, $responseShape);
        }

        if ($provider instanceof ContextAwarePresenting) {
            return $provider->presentForLearnerInContext($definition, $context);
        }

        return $provider->presentForLearner($definition);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function grade(array $definition, mixed $answer, GradingContext $context): GradeResult
    {
        $provider = $this->provider;

        if (!$this->isAvailable() || $provider === null) {
            return GradeResult::notScored($this->missFeedback())
                ->withAnsweredStatus($answer === null ? AnsweredStatus::Presented : AnsweredStatus::Answered);
        }

        // Recompute against the frozen definition; stored/client validity is
        // not authority to award marks for an over-selected response.
        if ($provider instanceof AnswerValidatingInterface
            && $provider->validateAnswer(
                $definition,
                $answer,
                DecodeContext::create(ShapeForDefinition::of($provider, $definition), $context->locale),
            )->exceedsCardinality()
        ) {
            return GradeResult::incorrect()->withAnsweredStatus(AnsweredStatus::Attempted);
        }

        if ($provider instanceof ContextAwareGrading) {
            return $provider->gradeInContext($definition, $answer, $context);
        }

        return $provider->grade($definition, $answer);
    }

    /**
     * Whether a human must mark this item, decided without looking at the
     * learner's response.
     *
     * An unresolvable type counts as pending rather than being skipped. All
     * four artifact types default to the external policy, so an unresolvable
     * key on a submitted attempt is exactly the case the placeholder exists
     * for: the item stays in the queue with an explanatory row naming the
     * missing package, and a grader can close it.
     *
     * @param array<string, mixed> $definition
     */
    public function requiresHumanGrader(
        array $definition,
        ?string $externalScored = null,
        ?GradingContext $context = null,
    ): bool {
        if ($externalScored !== null && ScoringIntent::fromWire($externalScored)->isHuman()) {
            return true;
        }

        $provider = $this->provider;

        if (!$this->isAvailable() || $provider === null) {
            return true;
        }

        if ($provider instanceof ManualGradingDecidingInterface) {
            return $provider->requiresHumanGrader($definition, $context ?? GradingContext::detached());
        }

        if (ScoringIntent::ofDefinition($definition)->isHuman()) {
            return true;
        }

        // The documented fallback: grade a null answer and read the session
        // status off the result. grade() is deterministic, so this is stable
        // and it never consults the learner's response, which is the whole
        // point of the question being asked this way.
        return $provider->grade($definition, null)->requiresManualGrading();
    }

    /**
     * The parts of one item, or the single roll-up part every type that
     * declares none has.
     *
     * The roll-up carries a null id, which is what an attempt_grades row
     * written for a whole question already stores, so the per-part path is
     * exercised by the free build's own six types without a composite
     * existing yet and without any row changing shape.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<QuestionPart>
     */
    public function parts(array $definition): array
    {
        $provider = $this->provider;

        if (!$this->isAvailable() || !$provider instanceof PartScoringInterface) {
            return [];
        }

        return $provider->parts($definition);
    }

    /**
     * The maximum a grader may award for one part of an item worth
     * $itemPoints, and the item's own maximum for a type with no parts.
     *
     * $itemPoints is the frozen value from the manifest, never the live
     * question row: an instructor reweighting a question after submission
     * must not move what a grader may award on an attempt already taken.
     *
     * @param array<string, mixed> $definition
     */
    public function partMaximum(array $definition, float $itemPoints, ?string $partId): float
    {
        $parts = $this->parts($definition);

        if ($parts === [] || $partId === null) {
            return $itemPoints;
        }

        $total = 0.0;

        foreach ($parts as $part) {
            $total += $part->weight;
        }

        foreach ($parts as $part) {
            if ($part->id === $partId) {
                return $part->maximumOf($itemPoints, $total);
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw ValidationException::forField('part_id', sprintf('"%s" is not a part of this question.', $partId));
    }

    /**
     * What a human's mark on this answer is multiplied by, which is 1.0 for
     * every type that declares no deduction and for a type whose provider is
     * gone.
     *
     * The design names one: an extended response under `word_limit_mode`
     * `penalise` multiplies the human's grade in proportion to the overage.
     * The rule belongs to the package that ships the type, so it is asked
     * rather than branched on here, and a missing provider deducts nothing
     * rather than guessing.
     *
     * @param array<string, mixed> $definition
     */
    public function manualGradeMultiplier(array $definition, mixed $answer, ?string $partId): float
    {
        $provider = $this->provider;

        if (!$this->isAvailable() || !$provider instanceof ManualGradeAdjustingInterface) {
            return 1.0;
        }

        // A multiplier outside [0, 1] is not a deduction, and a type that
        // returns one would silently inflate a mark past the maximum the
        // grader was shown.
        return max(0.0, min(1.0, $provider->manualGradeMultiplier($definition, $answer, $partId)));
    }

    /**
     * The part ids of one item that a human must mark, or [null] for the
     * single roll-up part of a type with no parts.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<string|null>
     */
    public function pendingPartIds(array $definition, ?string $externalScored = null): array
    {
        if (!$this->requiresHumanGrader($definition, $externalScored)) {
            return [];
        }

        $parts = $this->parts($definition);

        if ($parts === []) {
            return [null];
        }

        $ids = [];

        foreach ($parts as $part) {
            if ($part->requiresHumanGrader) {
                $ids[] = $part->id;
            }
        }

        // A composite the author declared human scored as a whole, with no
        // part saying so, is still one pending item rather than none.
        return $ids === [] ? [null] : $ids;
    }

    /**
     * The xAPI interaction token this item reports under.
     *
     * A registry miss reports "other", which is the same answer the design
     * gives a type with no fitting token: reporting a token for an item
     * whose provider is gone would claim a shape nothing can vouch for.
     * A token outside the closed set of ten is "other" for the same reason,
     * so a third-party provider cannot put an invented string on the wire.
     *
     * $scored is the placement's, from the frozen manifest, because one
     * type in the design answers differently for a marked and an unmarked
     * placement and "scored" is a questions column rather than a definition
     * field.
     */
    public function xapiInteractionType(bool $scored = true): string
    {
        $provider = $this->provider;

        if (!$this->isAvailable() || $provider === null) {
            return XapiVocabulary::TOKEN_OTHER;
        }

        if ($provider instanceof ScoredReportingInterface) {
            return XapiVocabulary::interactionToken($provider->xapiInteractionTypeWhen($scored));
        }

        if ($provider instanceof ReportingMetadataInterface) {
            return XapiVocabulary::interactionToken($provider->xapiInteractionType());
        }

        return XapiVocabulary::TOKEN_OTHER;
    }

    /**
     * The line an explanatory grade row carries, so an admin reading the
     * gradebook learns which package is missing rather than seeing a zero.
     */
    public function missFeedback(): ?string
    {
        $named = $this->package !== null && $this->package !== '';

        return match ($this->miss) {
            self::MISS_UNKNOWN_TYPE => NeutralStrings::say(
                $this->translator,
                $named ? NeutralStrings::MISS_UNKNOWN_TYPE_PACKAGE : NeutralStrings::MISS_UNKNOWN_TYPE,
                ['type' => $this->typeKey, 'package' => $this->package],
            ),
            self::MISS_UNSUPPORTED_VERSION => NeutralStrings::say(
                $this->translator,
                $named
                    ? NeutralStrings::MISS_UNSUPPORTED_VERSION_PACKAGE
                    : NeutralStrings::MISS_UNSUPPORTED_VERSION,
                ['type' => $this->typeKey, 'version' => $this->storedVersion ?? 0, 'package' => $this->package],
            ),
            self::MISS_DEFINITION_LOST => NeutralStrings::say(
                $this->translator,
                NeutralStrings::MISS_DEFINITION_LOST,
                ['type' => $this->typeKey],
            ),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function placeholderShape(array $definition, ?string $responseShape): array
    {
        // Only the prompt crosses, and only because a prompt is presentable
        // in every type in the catalogue. Nothing else of an unknown
        // definition can be trusted not to carry correctness.
        $prompt = $definition['prompt'] ?? '';

        return [
            'placeholder' => true,
            'type_key' => $this->typeKey,
            'reason' => $this->miss,
            'response_shape' => $responseShape,
            'prompt' => \is_string($prompt) ? $prompt : '',
        ];
    }

    /**
     * The shape column a placeholder renders through, taken from the row
     * when it has one and inferred conservatively otherwise.
     */
    public static function responseShapeOf(Question $question): ?string
    {
        return $question->responseShape;
    }
}
