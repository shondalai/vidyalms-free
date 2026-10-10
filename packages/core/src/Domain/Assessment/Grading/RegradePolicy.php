<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Grading;

/**
 * How a regrade decides what each affected answer is now worth.
 *
 * The four carry the names a migrating teacher already knows, from Canvas,
 * because a regrade is one of the few operations an instructor performs
 * under time pressure in front of a cohort that has already seen its marks.
 * A new vocabulary here would cost more than it could possibly buy.
 */
enum RegradePolicy: string
{
    /** The corrected key alone. A score may go down, and that is the point. */
    case CorrectedKeyOnly = 'corrected_key_only';

    /** The current and the previous key, so no score is reduced. */
    case BothKeys = 'both_keys';

    /** Any submitted answer earns the item. */
    case AnyAnswer = 'any_answer';

    /** Everyone gets the item, answered or not. */
    case FullCredit = 'full_credit';

    public static function fromKey(string $key): ?self
    {
        return self::tryFrom($key);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $policy): string => $policy->value, self::cases());
    }

    /**
     * Whether this policy re-runs the marking rule at all. Two of the four
     * do not: they award by fiat, which is why a regrade under either is
     * safe on an item whose provider is no longer installed.
     */
    public function regrades(): bool
    {
        return $this === self::CorrectedKeyOnly || $this === self::BothKeys;
    }

    /**
     * Whether this policy may overwrite a mark a human gave.
     *
     * Only full credit may, and only because "give everyone the item" is an
     * explicit instruction about a broken question rather than a
     * recomputation. The other three leave a human grade alone and say so in
     * a warning: a rater's judgement is not derivable from a key, corrected
     * or otherwise.
     */
    public function overridesHumanGrades(): bool
    {
        return $this === self::FullCredit;
    }
}
