<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

/**
 * One node of the boolean tree, taken from Moodle's shape because it is the
 * only model in the field that needs neither a schema change nor a catalogue
 * growth to express the next request.
 *
 * Negation lives in the operator and is pushed down to the leaf as a boolean
 * parameter, so no condition ever needs an inverted twin type. D2L, which has
 * no negation operator, ships the negative of every family as a separately
 * named condition; its catalogue doubled and its negatives drift out of sync
 * with its positives.
 *
 * showc is a boolean array the same length as c and applies under and and
 * not_or, where a failure identifies which child failed so hiding or
 * explaining is a per-child decision. show is a single boolean and applies
 * under or and not_and, where a failure means every child failed and there is
 * exactly one combined message. That split falls out of the logic rather than
 * from taste, and a length mismatch is refused on write because it is the
 * single most reported structural fault in the model this is taken from.
 */
final class AvailabilityNode
{
    public const OP_AND = 'and';
    public const OP_OR = 'or';
    public const OP_NOT_AND = 'not_and';
    public const OP_NOT_OR = 'not_or';

    /**
     * @param list<self|AvailabilityLeaf> $children
     * @param list<bool>                  $showc
     */
    public function __construct(
        public readonly string $op,
        public readonly array $children,
        public readonly bool $show = true,
        public readonly array $showc = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public static function operators(): array
    {
        return [self::OP_AND, self::OP_OR, self::OP_NOT_AND, self::OP_NOT_OR];
    }

    /**
     * Whether the node is negated. De Morgan turns not_and into an or over
     * negated children and not_or into an and over them, so the composer
     * folds two operators rather than four.
     */
    public function isNegated(): bool
    {
        return $this->op === self::OP_NOT_AND || $this->op === self::OP_NOT_OR;
    }

    /**
     * The effective fold after De Morgan: and or or.
     */
    public function effectiveOperator(): string
    {
        return match ($this->op) {
            self::OP_AND, self::OP_NOT_OR => self::OP_AND,
            default => self::OP_OR,
        };
    }

    /**
     * Whether showc applies. It does under and and not_or, where a failure
     * identifies which child failed.
     */
    public function usesPerChildVisibility(): bool
    {
        return $this->op === self::OP_AND || $this->op === self::OP_NOT_OR;
    }

    public function showsChild(int $index): bool
    {
        if (!$this->usesPerChildVisibility()) {
            return $this->show;
        }

        return $this->showc[$index] ?? true;
    }
}
