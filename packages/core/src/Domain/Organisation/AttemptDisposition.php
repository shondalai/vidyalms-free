<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * What a revoking path does to an attempt the learner still has open.
 *
 * An organisation administrator reclaiming a seat at minute forty of a sixty
 * minute attempt has to answer one question, and the product answered it by
 * refusing every subsequent save: the learner's answers stayed in
 * attempt_answer_events, no grade was ever written, and the person who did
 * the work had nothing to show for it. That is the defect this type exists to
 * remove.
 *
 * `submit_and_grade` is the default and is what a consumed seat gets unless a
 * caller says otherwise, because work already done is the learner's. `void`
 * exists for the case where the access should never have been granted, and it
 * abandons the attempt rather than pretending it was submitted.
 */
final class AttemptDisposition
{
    /** Close the attempt as a submission and grade what was stored in time. */
    public const SUBMIT_AND_GRADE = 'submit_and_grade';

    /** Abandon the attempt; nothing is graded and no result is published. */
    public const VOID = 'void';

    /** @var list<string> */
    public const DISPOSITIONS = [self::SUBMIT_AND_GRADE, self::VOID];

    private function __construct()
    {
    }

    /**
     * The disposition a request named, or the default when it named none.
     *
     * Refused rather than defaulted when it names something else, because a
     * misspelt "voide" silently grading an attempt somebody meant to void is
     * exactly the direction that cannot be undone.
     */
    public static function of(mixed $value): string
    {
        if ($value === null) {
            return self::SUBMIT_AND_GRADE;
        }

        if (!\is_string($value) || !\in_array($value, self::DISPOSITIONS, true)) {
            throw ValidationException::forField('attempt_disposition', 'Expected submit_and_grade or void.');
        }

        return $value;
    }
}
