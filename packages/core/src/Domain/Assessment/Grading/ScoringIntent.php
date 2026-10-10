<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Grading;

/**
 * What the author declared about who marks this item, mirroring QTI's
 * external-scored attribute on the outcome declaration.
 *
 * It lives in the definition as scoring_intent and is mirrored into the real
 * questions.external_scored column, because the pending-external-scoring
 * queue is a filtered query that has to run with the provider uninstalled.
 *
 * Pending-human is decided from this and never from whether an answer is
 * present. That is the fix for the item that never reached the grading
 * queue: an essay nobody opened was written as an automatic zero and the
 * attempt went straight to graded with the pass flag settled.
 */
enum ScoringIntent: string
{
    /** Response processing decides the mark. */
    case Machine = 'machine';

    /** A human decides the mark; response processing only records the response. */
    case Human = 'human';

    public static function fromWire(?string $value): self
    {
        return $value === null ? self::Machine : (self::tryFrom($value) ?? self::Machine);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function ofDefinition(array $definition): self
    {
        $declared = $definition['scoring_intent'] ?? null;

        return \is_string($declared) ? self::fromWire($declared) : self::Machine;
    }

    public function isHuman(): bool
    {
        return $this === self::Human;
    }
}
