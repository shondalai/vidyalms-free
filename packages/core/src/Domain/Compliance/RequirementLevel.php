<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Compliance;

/**
 * How hard a requirement is, on the requirement and never on the course.
 *
 * The same course is mandatory for one organisation and optional for another,
 * so nothing goes on `courses`. That is what finally gives a compliance report a
 * denominator: without it, "how many of the people who had to do this have done
 * it" has no numerator and no denominator, only a count of completions.
 *
 * The four words are Docebo's enrolment priority.
 */
final class RequirementLevel
{
    public const MANDATORY = 'mandatory';
    public const REQUIRED = 'required';
    public const RECOMMENDED = 'recommended';
    public const OPTIONAL = 'optional';

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::MANDATORY, self::REQUIRED, self::RECOMMENDED, self::OPTIONAL];
    }

    /**
     * An unrecognised word reads as {@see MANDATORY}.
     *
     * The strict direction, because a requirement whose level nothing can read
     * is a requirement somebody wrote down and an auditor will ask about, and
     * counting it as optional would drop it out of the denominator it was
     * created to be in.
     */
    public static function of(?string $level): string
    {
        return $level !== null && \in_array($level, self::all(), true) ? $level : self::MANDATORY;
    }

    /** Whether a person failing this is counted late rather than merely nudged. */
    public static function countsInDenominator(string $level): bool
    {
        return $level === self::MANDATORY || $level === self::REQUIRED;
    }
}
