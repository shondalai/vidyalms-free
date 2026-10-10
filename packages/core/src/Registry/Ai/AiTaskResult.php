<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * What a task produced.
 *
 * It never carries a domain entity, and it never carries a mark, a score, a
 * confidence or a risk value, because no such field exists anywhere in this
 * design: a number a task emitted beside a learner's work becomes a grade the
 * moment a screen renders it, and no tier of review permits that.
 */
final class AiTaskResult
{
    /**
     * @param list<AiDraftCandidate>   $drafts
     * @param list<PublicationFinding> $findings
     * @param list<string>             $warnings
     */
    private function __construct(
        public readonly array $drafts,
        public readonly array $findings,
        public readonly array $warnings,
        public readonly CostRecord $cost,
        public readonly ?AiRefusal $refusal,
    ) {
    }

    /**
     * @param list<AiDraftCandidate>   $drafts
     * @param list<PublicationFinding> $findings
     * @param list<string>             $warnings
     */
    public static function create(
        array $drafts = [],
        array $findings = [],
        array $warnings = [],
        ?CostRecord $cost = null,
        ?AiRefusal $refusal = null,
    ): self {
        return new self($drafts, $findings, $warnings, $cost ?? CostRecord::none(), $refusal);
    }

    public static function refused(AiRefusal $refusal, ?CostRecord $cost = null): self
    {
        return new self([], [], [], $cost ?? CostRecord::none(), $refusal);
    }

    public function wasRefused(): bool
    {
        return $this->refusal !== null;
    }
}
