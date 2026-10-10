<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\FieldDisclosure;

/**
 * The three answers a field disclosure policy may give, and nothing else.
 *
 * `allow` is the field as the report computed it. `redact` keeps the column and
 * empties the cell, which is what a reader needs when the shape of the row
 * matters and the value does not. `omit` removes the column entirely, so a
 * reader cannot tell a hidden value from an absent one and a CSV header does
 * not advertise a field nobody may read.
 *
 * It is a closed vocabulary rather than a registry for the reason the design
 * gives for the two compliance status axes: everything downstream counts and
 * buckets on it, and an open vocabulary has no denominator. A policy that wants
 * a finer distinction expresses it by choosing one of these three.
 */
final class FieldDisclosureDecision
{
    public const ALLOW = 'allow';
    public const REDACT = 'redact';
    public const OMIT = 'omit';

    /** What a cell reads as once a policy has redacted it. */
    public const REDACTION = null;

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ALLOW, self::REDACT, self::OMIT];
    }

    /**
     * A provider's answer, narrowed to the vocabulary.
     *
     * Anything unrecognised reads as {@see OMIT}, which is the published miss
     * direction applied to a provider that answers rather than to a provider
     * that is absent: a policy returning a word this build does not know is a
     * policy this build cannot honour, and the safe reading of a disclosure
     * decision it cannot honour is not to disclose.
     */
    public static function of(string $decision): string
    {
        return \in_array($decision, self::all(), true) ? $decision : self::OMIT;
    }
}
