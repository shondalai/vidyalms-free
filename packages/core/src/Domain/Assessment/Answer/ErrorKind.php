<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Answer;

/**
 * The closed core set of shape errors: the ones the error summary reasons
 * about and groups by.
 *
 * Closed is not the same as exhaustive, and the catalogue already outgrows
 * it. A word count outside a limit, a recording below a minimum duration and
 * an unmet play count are validity failures none of these eight names, and
 * folding them into OUT_OF_RANGE destroys the authored message the summary
 * repeats verbatim. So a type may contribute an additional kind as a
 * namespaced string under the same x_<vendor>_ rule type keys use, and
 * AnswerError carries a plain string rather than this enum for that reason.
 *
 * What stays absolutely closed is that no kind, core or contributed, may be
 * derived from correctness. That is what stops a future type author leaking
 * the key through a validation message.
 */
enum ErrorKind: string
{
    case TooManySelected = 'TOO_MANY_SELECTED';
    case TooFewSelected = 'TOO_FEW_SELECTED';
    case RequiredGapEmpty = 'REQUIRED_GAP_EMPTY';
    case OutOfRange = 'OUT_OF_RANGE';
    case UnsupportedFileType = 'UNSUPPORTED_FILE_TYPE';
    case ValueNotNumeric = 'VALUE_NOT_NUMERIC';
    case InvalidPermutation = 'INVALID_PERMUTATION';
    case InvalidEnvelope = 'INVALID_ENVELOPE';

    /**
     * Whether a machine key is a core kind or a well-formed contributed one.
     * A contributed kind must be namespaced, so a vendor cannot claim a core
     * name and cannot collide with another vendor.
     */
    public static function isWellFormed(string $kind): bool
    {
        return self::tryFrom($kind) !== null || preg_match('/^x_[a-z0-9]+_[a-z0-9_]+$/', $kind) === 1;
    }
}
