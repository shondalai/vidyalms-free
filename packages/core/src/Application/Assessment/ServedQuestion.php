<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ChoiceSequence;

/**
 * One question of an attempt as the rest of the application sees it: the
 * frozen manifest row joined to the definition it was graded from.
 *
 * Everything downstream of StartAttempt reads this, whether the attempt has
 * a frozen manifest or predates one, so grading, review, the grading queue
 * and a regrade all agree on the served set without every one of them
 * knowing which source it came from.
 */
final class ServedQuestion
{
    /** @var array<string, mixed> */
    public readonly array $definition;

    /** @var array<string, mixed> */
    public readonly array $presented;

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $presented
     * @param array<string, mixed> $policyParams
     */
    public function __construct(
        public readonly int $questionId,
        public readonly string $typeKey,
        public readonly int $definitionVersion,
        public readonly int $position,
        public readonly int $screen,
        public readonly float $points,
        public readonly bool $scored,
        array $definition,
        array $presented,
        public readonly ChoiceSequence $choiceSequence,
        public readonly string $definitionHash,
        public readonly AnsweredStatus $answeredStatus,
        public readonly ?string $responseShape = null,
        public readonly ?string $externalScored = null,
        public readonly ?int $stimulusId = null,
        public readonly bool $frozen = true,
        /**
         * True when the manifest named a frozen definition the store no
         * longer holds. Content addressing exists so a live edit cannot move
         * a mark under a graded attempt, and rebuilding the missing entry
         * from the live row would hand that mark straight back: the item
         * would be re-marked against whatever the author has since written,
         * silently, and still report itself as frozen. A lost definition is
         * a registry miss instead, so the item grades notscored with an
         * explanatory row.
         */
        public readonly bool $definitionLost = false,
        /**
         * The marking rule the placement overrode the question's own with,
         * as frozen on the manifest. The resolution order is slot, then
         * question, then definition, and this is the first of the three.
         */
        public readonly ?string $policyKey = null,
        /** @var array<string, mixed> */
        public readonly array $policyParams = [],
    ) {
        $this->definition = $definition;
        $this->presented = $presented;
    }

    /**
     * An unscored item contributes to neither sum of the attempt totals, so
     * a survey question cannot move a pass percentage.
     */
    public function contributesToScore(): bool
    {
        return $this->scored;
    }
}
