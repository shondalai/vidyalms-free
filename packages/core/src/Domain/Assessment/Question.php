<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoringIntent;

/**
 * One question of a quiz. The definition is the authoring-side shape owned by
 * the question type provider; it contains correctness and must never leave
 * the server unfiltered. Removed questions stay in the table so historical
 * attempts keep their referential integrity.
 *
 * Five fields beside the definition are real columns rather than JSON, and
 * the rule is the same for all five: something outside the owning provider
 * has to read them, and has to be able to read them with the provider
 * uninstalled.
 *
 *  - definitionVersion, so a batch upgrade finds affected rows with an index
 *    rather than a portability-hostile JSON path query, and so a row newer
 *    than the resolved provider can be recognised before it is mismarked.
 *  - responseShape, because the generic fallback renderer, the answer
 *    validator and an export must all work with the provider gone.
 *  - scored, because item analysis filters on it and the totals exclude an
 *    unscored item from both sums.
 *  - scoringPolicy, because reporting filters and groups on the policy key.
 *    It is a derived mirror of the definition's policy reference, written by
 *    the same code path, never an independent input.
 *  - externalScored, because the pending-human queue is a filtered query.
 */
final class Question
{
    public const STATE_PUBLISHED = 'published';
    public const STATE_REMOVED = 'removed';

    /** @var array<string, mixed> */
    public readonly array $scoringParams;

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $scoringParams
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $quizId,
        public readonly string $type,
        public readonly int $position,
        public readonly float $points,
        public readonly array $definition,
        public readonly string $state,
        public readonly int $definitionVersion = 1,
        public readonly bool $scored = true,
        public readonly ?string $responseShape = null,
        public readonly ?string $scoringPolicy = null,
        array $scoringParams = [],
        public readonly ?string $externalScored = null,
        public readonly ?int $stimulusId = null,
        public readonly int $screen = 0,
    ) {
        $this->scoringParams = $scoringParams;
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->quizId,
            $this->type,
            $this->position,
            $this->points,
            $this->definition,
            $this->state,
            $this->definitionVersion,
            $this->scored,
            $this->responseShape,
            $this->scoringPolicy,
            $this->scoringParams,
            $this->externalScored,
            $this->stimulusId,
            $this->screen,
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $scoringParams
     */
    public function withRevision(
        array $definition,
        float $points,
        ?bool $scored = null,
        ?string $scoringPolicy = null,
        ?array $scoringParams = null,
    ): self {
        return new self(
            $this->id,
            $this->quizId,
            $this->type,
            $this->position,
            $points,
            $definition,
            $this->state,
            $this->definitionVersion,
            $scored ?? $this->scored,
            $this->responseShape,
            $scoringPolicy ?? $this->scoringPolicy,
            $scoringParams ?? $this->scoringParams,
            $this->externalScored,
            $this->stimulusId,
            $this->screen,
        );
    }

    /**
     * The derived mirrors, written by the one code path that owns them: the
     * repository, on the way to the row.
     *
     * @param array<string, mixed> $scoringParams
     */
    public function withDerived(
        int $definitionVersion,
        ?string $responseShape,
        ?string $scoringPolicy,
        array $scoringParams,
        ?string $externalScored,
    ): self {
        return new self(
            $this->id,
            $this->quizId,
            $this->type,
            $this->position,
            $this->points,
            $this->definition,
            $this->state,
            $definitionVersion,
            $this->scored,
            $responseShape,
            $scoringPolicy,
            $scoringParams,
            $externalScored,
            $this->stimulusId,
            $this->screen,
        );
    }

    public function removed(): self
    {
        return new self(
            $this->id,
            $this->quizId,
            $this->type,
            $this->position,
            $this->points,
            $this->definition,
            self::STATE_REMOVED,
            $this->definitionVersion,
            $this->scored,
            $this->responseShape,
            $this->scoringPolicy,
            $this->scoringParams,
            $this->externalScored,
            $this->stimulusId,
            $this->screen,
        );
    }

    public function isRemoved(): bool
    {
        return $this->state === self::STATE_REMOVED;
    }

    /**
     * An unscored question contributes to neither sum of the attempt totals.
     *
     * Without it an opinion scale or a media acknowledgement is either
     * unauthorable or a free mark that moves the pass percentage, which is
     * the third of the five free-build defects.
     */
    public function contributesToScore(): bool
    {
        return $this->scored;
    }

    /**
     * Who marks this item, read from the column when it has been written and
     * from the definition otherwise.
     *
     * The column is what the pending-human queue filters on with the
     * provider uninstalled; the definition is what the provider declared. A
     * row written before the column existed answers from the definition, so
     * the two never disagree about an existing question.
     */
    public function scoringIntent(): ScoringIntent
    {
        if ($this->externalScored !== null) {
            return ScoringIntent::fromWire($this->externalScored);
        }

        return ScoringIntent::ofDefinition($this->definition);
    }
}
