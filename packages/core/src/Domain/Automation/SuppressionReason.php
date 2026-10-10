<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * Why a step did nothing, as a closed core vocabulary an action may extend.
 *
 * Real values in a real column, for the reason notification_queue already
 * gives about its own suppressed_reason: counting suppressions without their
 * reason cannot tell a site whether a rule is throttled, gated by its guard
 * tree or silenced by a loop protection. The rules screen groups by it.
 */
final class SuppressionReason
{
    /** The step's guard tree said no at execution time. */
    public const GUARD_FAILED = 'guard_failed';

    /** The rule's entry tree no longer held when the fan-out resolved it. */
    public const CONDITIONS_NO_LONGER_HOLD = 'conditions_no_longer_hold';

    /** Loop protection one: the subject's last run for this rule is inside the cooldown. */
    public const COOLDOWN = 'cooldown';

    /** Loop protection two: the cascade depth ceiling, or a rule already on the chain. */
    public const CASCADE_CEILING = 'cascade_ceiling';
    public const CASCADE_REPEAT = 'cascade_repeat';

    /** The rule's own caps: times per subject, or total completions. */
    public const TIMES_PER_SUBJECT = 'times_per_subject';
    public const TOTAL_COMPLETIONS = 'total_completions';

    /**
     * The rule's re-entry mode said this subject may not enter again.
     *
     * never is a stronger statement than the occurrence unique index makes: a
     * second occurrence key is still a second entry, and a rule set to never
     * must refuse it. Without this the shipped hourly sweep re-entered the
     * same subject every hour, because each hour bucket is a different
     * occurrence key and nothing else looked at the mode.
     */
    public const REENTRY_BLOCKED = 'reentry_blocked';

    /** The subject was already in the state the action would have produced. */
    public const ALREADY_IN_STATE = 'already_in_state';

    /** A dry run reached the effect boundary and stopped there. */
    public const DRY_RUN = 'dry_run';

    /**
     * The rule stored no longer matches what was armed, so the step is not
     * enqueued. This is what makes the arming digest a control and not a
     * record.
     */
    public const ARMING_MOVED = 'arming_moved';

    /**
     * The step names a delay anchor no provider could resolve, so it is held
     * rather than released. An unresolved anchor leaves the thing blocked
     * with no opensAt, which is the one registry miss whose obvious reading
     * is the dangerous one.
     */
    public const ANCHOR_UNRESOLVED = 'anchor_unresolved';

    /** The subject fell outside the rule's declared scope. */
    public const OUT_OF_SCOPE = 'out_of_scope';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::GUARD_FAILED,
            self::CONDITIONS_NO_LONGER_HOLD,
            self::COOLDOWN,
            self::CASCADE_CEILING,
            self::CASCADE_REPEAT,
            self::TIMES_PER_SUBJECT,
            self::TOTAL_COMPLETIONS,
            self::REENTRY_BLOCKED,
            self::ALREADY_IN_STATE,
            self::DRY_RUN,
            self::ARMING_MOVED,
            self::ANCHOR_UNRESOLVED,
            self::OUT_OF_SCOPE,
        ];
    }
}
