<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * The comparisons a mapping rule may make, and no others.
 *
 * Six of them are the filter operators an administrator who writes directory
 * filters already knows: equals, not equals, contains, starts with, ends with
 * and present. The seventh is a bounded regular expression, and "bounded" is
 * the whole of its safety: a length cap, no back references, and a refusal at
 * save of any pattern the engine will not compile.
 *
 * The set is deliberately small. Every operator here is total, deterministic
 * and cheap, which is what lets the evaluator promise a decision for every
 * assertion rather than a decision or a timeout.
 */
final class MappingOperator
{
    public const EQUALS = 'eq';
    public const NOT_EQUALS = 'ne';
    public const CONTAINS = 'co';
    public const STARTS_WITH = 'sw';
    public const ENDS_WITH = 'ew';
    public const PRESENT = 'pr';
    public const MATCHES = 're';

    /** @var list<string> */
    public const OPERATORS = [
        self::EQUALS,
        self::NOT_EQUALS,
        self::CONTAINS,
        self::STARTS_WITH,
        self::ENDS_WITH,
        self::PRESENT,
        self::MATCHES,
    ];

    /** The one operator that needs no value, because it asks whether anything is there. */
    public const VALUELESS = [self::PRESENT];

    /**
     * The longest pattern a bounded regular expression may be.
     *
     * A cap rather than a cleverness budget. A pattern this long is already
     * unreadable to the administrator who has to maintain it, and the cap is
     * what makes "bounded" a number a reviewer can check.
     */
    public const MAX_PATTERN = 200;

    private function __construct()
    {
    }

    public static function isKnown(string $operator): bool
    {
        return \in_array($operator, self::OPERATORS, true);
    }

    public static function needsValue(string $operator): bool
    {
        return !\in_array($operator, self::VALUELESS, true);
    }

    /**
     * Whether the candidate satisfies this comparison.
     *
     * Total: an unknown operator is false rather than an exception, because a
     * stored rule can outlive the vocabulary that wrote it and the fail-closed
     * direction for "I do not understand this rule" is that it grants nothing.
     * The refusal is still surfaced, by the evaluator, which records the rule
     * as unreadable rather than as unmatched.
     */
    public static function satisfied(string $operator, string $candidate, ?string $value): bool
    {
        if ($operator === self::PRESENT) {
            return $candidate !== '';
        }

        if ($value === null) {
            return false;
        }

        return match ($operator) {
            self::EQUALS => $candidate === $value,
            self::NOT_EQUALS => $candidate !== $value,
            self::CONTAINS => $value !== '' && str_contains($candidate, $value),
            self::STARTS_WITH => $value !== '' && str_starts_with($candidate, $value),
            self::ENDS_WITH => $value !== '' && str_ends_with($candidate, $value),
            self::MATCHES => self::matches($candidate, $value),
            default => false,
        };
    }

    /**
     * Whether a pattern is one this product will store.
     *
     * Checked at save, and checked again here, because a row written before a
     * rule tightened is a row the evaluator still has to survive.
     */
    public static function isStorablePattern(string $pattern): bool
    {
        if ($pattern === '' || \strlen($pattern) > self::MAX_PATTERN) {
            return false;
        }

        // A back reference turns a linear match into one that can be made to
        // take exponential time on an input the attacker chooses, and an
        // asserted group value is an input somebody else chooses.
        if (preg_match('/\\\\[1-9]/', $pattern) === 1) {
            return false;
        }

        return @preg_match(self::delimited($pattern), '') !== false;
    }

    private static function matches(string $candidate, string $pattern): bool
    {
        if (!self::isStorablePattern($pattern)) {
            return false;
        }

        return @preg_match(self::delimited($pattern), $candidate) === 1;
    }

    /**
     * The pattern with its delimiters and its flags, composed here so a rule
     * author cannot supply either.
     *
     * The author writes the pattern and nothing else: supplying the delimiter
     * would let them supply the modifiers with it, and one of those modifiers
     * evaluates the replacement as code.
     */
    private static function delimited(string $pattern): string
    {
        return '/' . str_replace('/', '\\/', $pattern) . '/u';
    }
}
