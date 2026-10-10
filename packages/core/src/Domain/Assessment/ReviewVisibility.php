<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * The review visibility matrix: what a learner may see of their own attempt,
 * per phase.
 *
 * show_results stays the coarse switch every client already reads, and this
 * is the matrix underneath it, so a quiz can reveal marks immediately and
 * the correct answers only after it closes. A null stored matrix derives
 * from show_results, which is why no existing quiz changes behaviour.
 */
final class ReviewVisibility
{
    public const PHASE_DURING_ATTEMPT = 'during_attempt';
    public const PHASE_IMMEDIATELY_AFTER = 'immediately_after';
    public const PHASE_LATER_WHILE_OPEN = 'later_while_open';
    public const PHASE_AFTER_CLOSE = 'after_close';

    public function __construct(
        public readonly ReviewVisibilityPhase $duringAttempt,
        public readonly ReviewVisibilityPhase $immediatelyAfter,
        public readonly ReviewVisibilityPhase $laterWhileOpen,
        public readonly ReviewVisibilityPhase $afterClose,
    ) {
    }

    /**
     * The matrix a quiz with no stored one runs under, derived from the
     * coarse switch so that the two can never disagree.
     */
    public static function derivedFrom(string $showResults): self
    {
        if ($showResults === Quiz::SHOW_RESULTS_NEVER) {
            $nothing = ReviewVisibilityPhase::nothing();

            return new self($nothing, $nothing, $nothing, $nothing);
        }

        $after = ReviewVisibilityPhase::everything();

        return new self(ReviewVisibilityPhase::nothing(), $after, $after, $after);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function toArray(): array
    {
        return [
            self::PHASE_DURING_ATTEMPT => $this->duringAttempt->toArray(),
            self::PHASE_IMMEDIATELY_AFTER => $this->immediatelyAfter->toArray(),
            self::PHASE_LATER_WHILE_OPEN => $this->laterWhileOpen->toArray(),
            self::PHASE_AFTER_CLOSE => $this->afterClose->toArray(),
        ];
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    public static function fromArray(?array $stored, string $showResults): self
    {
        $derived = self::derivedFrom($showResults);

        if ($stored === null) {
            return $derived;
        }

        return new self(
            ReviewVisibilityPhase::fromArray(self::phase($stored, self::PHASE_DURING_ATTEMPT), $derived->duringAttempt),
            ReviewVisibilityPhase::fromArray(
                self::phase($stored, self::PHASE_IMMEDIATELY_AFTER),
                $derived->immediatelyAfter,
            ),
            ReviewVisibilityPhase::fromArray(
                self::phase($stored, self::PHASE_LATER_WHILE_OPEN),
                $derived->laterWhileOpen,
            ),
            ReviewVisibilityPhase::fromArray(self::phase($stored, self::PHASE_AFTER_CLOSE), $derived->afterClose),
        );
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>|null
     */
    private static function phase(array $stored, string $key): ?array
    {
        $value = $stored[$key] ?? null;

        if (!\is_array($value)) {
            return null;
        }

        $phase = [];

        /** @var mixed $item */
        foreach ($value as $name => $item) {
            $phase[(string) $name] = $item;
        }

        return $phase;
    }
}
