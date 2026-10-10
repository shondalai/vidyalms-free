<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

/**
 * What a moderation pass found, as categories and never as a score.
 *
 * A probability would be a number a screen renders beside a person's work,
 * and this design refuses those everywhere for the same reason: an
 * unexplainable score is a score nobody can contest. So a finding names the
 * category and the segment label it was found on, and the runner decides.
 */
final class ModerationFindings
{
    /** @var list<array{category: string, label: string}> */
    public readonly array $findings;

    /**
     * @param list<array{category: string, label: string}> $findings
     */
    private function __construct(
        public readonly bool $blocked,
        array $findings,
        public readonly bool $assessed,
    ) {
        $this->findings = $findings;
    }

    /** Nothing found, and the pass really ran. */
    public static function clean(): self
    {
        return new self(false, [], true);
    }

    /**
     * @param list<array{category: string, label: string}> $findings
     */
    public static function blocked(array $findings): self
    {
        return new self(true, $findings, true);
    }

    /**
     * @param list<array{category: string, label: string}> $findings
     */
    public static function advisory(array $findings): self
    {
        return new self(false, $findings, true);
    }

    /**
     * No pass ran. Recorded as `moderation_unavailable` rather than as a
     * pass, because pretending an absent check succeeded is the one answer a
     * site owner can never be given.
     */
    public static function unavailable(): self
    {
        return new self(false, [], false);
    }
}
