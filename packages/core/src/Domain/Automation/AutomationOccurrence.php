<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * The two synthetic references the automation engine mints, composed here so
 * no caller spells one by hand.
 *
 * The step digest itself is NOT here. It is computed once, in
 * Registry\Context\AutomationActionContext, because a provider must never be
 * able to supply its own key and a second composition anywhere would let the
 * ledger and the queue disagree about what one effect is. This class holds
 * only the references that identify an occurrence, which are inputs to that
 * digest rather than a second version of it.
 */
final class AutomationOccurrence
{
    private function __construct()
    {
    }

    /**
     * The event reference for a run created by an emit_event action.
     *
     * It names the emitting rule and run, so the chain an operator reads in
     * automation_runs.rule_chain has something to join against, and so two
     * emissions from one run of one event key are one occurrence rather than
     * two.
     */
    public static function emittedReference(int $emittingRuleId, int $emittingRunId, string $eventKey): string
    {
        return sprintf('automation:%d:%d:%s', $emittingRuleId, $emittingRunId, $eventKey);
    }

    /** The widest an occurrence key may be, which is what its two columns are. */
    public const KEY_LIMIT = 64;

    /**
     * The bounded form of the same reference, for the occurrence key column.
     *
     * The reference above is the event ref, which has 190 characters to live
     * in. The occurrence key has 64, and passing the reference straight
     * through as the period bucket minted keys of up to 115 for a 100
     * character event key: SQLite stored them and the harness stayed green
     * while MySQL in strict mode raised 1406 and the receiving run was never
     * written at all, and without strict mode truncated at 64 and merged two
     * distinct emissions onto one occurrence.
     *
     * The event key half is hashed rather than truncated, for the reason the
     * step digest is hashed: a distinct key still produces a distinct bucket,
     * where a truncation makes two of them one silently. The rule and run
     * stay readable, so the chain an operator reads in
     * automation_runs.rule_chain still has something to join against.
     */
    public static function emittedBucket(int $emittingRuleId, int $emittingRunId, string $eventKey): string
    {
        $bucket = sprintf(
            'automation:%d:%d:%s',
            $emittingRuleId,
            $emittingRunId,
            substr(hash('sha256', $eventKey), 0, 16),
        );

        // Unreachable with real ids, and asserted rather than assumed: an
        // occurrence key wider than its column is a run that is never written.
        return \strlen($bucket) <= self::KEY_LIMIT
            ? $bucket
            : substr(hash('sha256', $bucket), 0, self::KEY_LIMIT);
    }

    /**
     * The occurrence key a repeat entry uses under ReentryMode::Always.
     *
     * always is the only mode that wants a distinct run inside one
     * occurrence, and it gets one by carrying the entry ordinal in the key
     * rather than by dropping the unique index that is the re-entry guard.
     */
    public static function repeatKey(string $occurrenceKey, int $ordinal): string
    {
        return $ordinal < 2 ? $occurrenceKey : $occurrenceKey . '#' . $ordinal;
    }
}
