<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

/**
 * What one route does with what it is sent, in the shape a published data
 * rubric asks a supplier to state.
 *
 * It is per route rather than per product because the honest answer differs
 * by route: one host may document that it stores no customer input or output
 * and that nobody trains on it, and a route to a different provider has
 * entirely different terms.
 *
 * Every member is a tri-state on purpose. A route that does not know whether
 * its upstream trains on inputs answers null, which the privacy page renders
 * as "not declared by this route", and that is a better answer than a false
 * that nobody can stand behind.
 */
final class DataHandling
{
    private function __construct(
        public readonly ?bool $retainsInput,
        public readonly ?bool $retainsOutput,
        public readonly ?bool $trainsOnInput,
        public readonly ?string $processingRegion,
        public readonly ?int $retentionDays,
        public readonly ?string $termsRef,
    ) {
    }

    public static function of(
        ?bool $retainsInput = null,
        ?bool $retainsOutput = null,
        ?bool $trainsOnInput = null,
        ?string $processingRegion = null,
        ?int $retentionDays = null,
        ?string $termsRef = null,
    ): self {
        return new self($retainsInput, $retainsOutput, $trainsOnInput, $processingRegion, $retentionDays, $termsRef);
    }

    /**
     * A route that keeps nothing and sends nothing anywhere, which is the
     * only claim a bridge with no transport is entitled to make.
     */
    public static function nothingLeavesTheSite(): self
    {
        return new self(false, false, false, 'local', 0, null);
    }

    /** Nothing stated. Rendered as "not declared by this route". */
    public static function undeclared(): self
    {
        return new self(null, null, null, null, null, null);
    }

    public function isDeclared(): bool
    {
        return $this->retainsInput !== null
            || $this->retainsOutput !== null
            || $this->trainsOnInput !== null
            || $this->processingRegion !== null;
    }
}
