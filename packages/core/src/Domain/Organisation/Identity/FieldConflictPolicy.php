<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * What happens when a provisioning source and a person disagree about a field.
 *
 * Three answers, and the middle one is the one that matters.
 *
 * **sticky** is the human edit winning. An administrator corrected a job title
 * the directory has wrong, and the next sync must not silently undo it. The
 * field is marked as held locally and the source stops writing it until
 * somebody releases it. Without this, the only way to keep a correction is to
 * fix the directory, which is a system the administrator very often cannot
 * edit.
 *
 * **source_wins** is the governed row: the directory owns the field, the
 * console shows it read only, and a person who wants it changed changes it
 * where it is governed. That is what NamesGoverningSource exists to render.
 *
 * **provisioning_only** seeds a field when the row is created and never
 * touches it again, which is the shape a creation-only source needs: it knows
 * the person's name on the day they first signed in and has no standing claim
 * on it afterwards.
 */
final class FieldConflictPolicy
{
    /** A person's edit is held and the source stops writing that field. */
    public const STICKY = 'sticky';

    /** The source owns the field and the console shows it read only. */
    public const SOURCE_WINS = 'source_wins';

    /** The source seeds the field once, at creation, and never overwrites it. */
    public const PROVISIONING_ONLY = 'provisioning_only';

    /** @var list<string> */
    public const POLICIES = [self::STICKY, self::SOURCE_WINS, self::PROVISIONING_ONLY];

    private function __construct()
    {
    }

    public static function isKnown(string $policy): bool
    {
        return \in_array($policy, self::POLICIES, true);
    }

    /**
     * Whether this policy permits a source to overwrite a field a person has
     * already set.
     *
     * The default direction for anything unrecognised is no. A policy word
     * this build does not know is a source written against a vocabulary that
     * has moved, and the safe reading of that is that it does not get to
     * overwrite an administrator's correction.
     */
    public static function mayOverwriteHumanEdit(string $policy): bool
    {
        return $policy === self::SOURCE_WINS;
    }

    /** Whether this policy writes a field on a row it did not create. */
    public static function writesAfterCreation(string $policy): bool
    {
        return $policy === self::SOURCE_WINS || $policy === self::STICKY;
    }
}
