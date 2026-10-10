<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Automation\CascadeChain;
use Shondalai\VidyaLMS\Core\Domain\Automation\ReentryMode;
use Shondalai\VidyaLMS\Core\Domain\Automation\SuppressionReason;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRunRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The three loop protections, in one object, because they only work together.
 *
 * All three as first written failed on the same two-rule cycle: rule A on
 * trigger T1 emitting T2, rule B on trigger T2 emitting T1, both free actions,
 * both at cooldown zero, each cycle presenting as a fresh depth-zero run of a
 * different rule.
 *
 * 1. **Provenance on every write an automation makes**, and the chain check
 *    reads it: a rule already on the current cascade is refused. Mechanism one
 *    was same-rule only and could not see the cycle at all.
 * 2. **A cascade depth counter**, checked before every enqueue against a hard
 *    site ceiling, inherited through the event envelope rather than restarted.
 *    Mechanism two started every event-created run at the column default of
 *    zero, so the counter could never reach the ceiling.
 * 3. **A per-rule per-subject cooldown window**, defaulting to a non-zero site
 *    setting. Mechanism three was off by default.
 *
 * Zapier auto-disables a Zap erroring 95 percent of the time over seven days
 * and detects nothing at all when every run succeeds, which is exactly what a
 * loop looks like. That is why all three ship rather than one.
 *
 * The rule's own re-entry mode is read here too, beside the three. It is not a
 * fourth loop protection, it is the author's own statement about repetition,
 * and it was stored, validated and presented while nothing consulted it: an
 * hourly sweep presents a fresh occurrence key every hour, so a rule set to
 * never re-entered the same subject every hour and the unique index that was
 * supposed to be the guard never saw two rows with the same key.
 */
final class AutomationLoopGuard
{
    /** The site ceiling's setting name, and the default it falls back to. */
    public const CEILING_SETTING = 'automation.cascade_ceiling';
    public const DEFAULT_CEILING = 5;

    /** The default the builder pre-fills into a new rule's cooldown_seconds. */
    public const COOLDOWN_SETTING = 'automation.default_cooldown_seconds';
    public const DEFAULT_COOLDOWN = 300;

    public function __construct(
        private readonly SqlAutomationRunRepository $runs,
        private readonly ClockPort $clock,
        private readonly ?Settings $settings = null,
    ) {
    }

    public function ceiling(): int
    {
        $configured = $this->settings?->getInt(self::CEILING_SETTING, self::DEFAULT_CEILING) ?? self::DEFAULT_CEILING;

        return max(1, $configured);
    }

    public function defaultCooldownSeconds(): int
    {
        $configured = $this->settings?->getInt(self::COOLDOWN_SETTING, self::DEFAULT_COOLDOWN)
            ?? self::DEFAULT_COOLDOWN;

        // Floored at one rather than at zero: zero was one of the three
        // protections being off by default, and the whole point of the site
        // setting is that the builder pre-fills something non-zero.
        return max(1, $configured);
    }

    /**
     * Why this rule may not run for this subject now, or null when it may.
     *
     * Every answer is a named suppression reason rather than a boolean,
     * because counting suppressions without their reason cannot tell an
     * operator whether a rule is looping, throttled or simply capped.
     */
    public function refusalFor(
        AutomationRule $rule,
        ?string $subjectType,
        ?int $subjectId,
        int $cascadeDepth,
        CascadeChain $chain,
        bool $dryRun = false,
    ): ?string {
        $ruleId = $rule->id ?? 0;

        // Mechanism one. It is checked first because it is the only one that
        // sees a cycle whose every run succeeds.
        if ($ruleId > 0 && $chain->contains($ruleId)) {
            return SuppressionReason::CASCADE_REPEAT;
        }

        // Mechanism two.
        if ($cascadeDepth >= $this->ceiling()) {
            return SuppressionReason::CASCADE_CEILING;
        }

        // A preview never occupies a cap, a cooldown or the re-entry guard.
        // The three keys carry dry_run precisely so rehearsing a rule costs
        // the subject nothing, and a guard that could not tell a preview from
        // a real run would be a preview that suppressed the thing it
        // previewed.
        if ($dryRun) {
            return null;
        }

        // Mechanism three.
        if ($rule->cooldownSeconds > 0 && $ruleId > 0) {
            $last = $this->runs->lastRunAtForSubject($ruleId, $subjectType, $subjectId);

            if ($last !== null) {
                $reopensAt = $last->modify('+' . $rule->cooldownSeconds . ' seconds');

                if ($reopensAt > $this->clock->now()) {
                    return SuppressionReason::COOLDOWN;
                }
            }
        }

        // The re-entry mode, read rather than only stored. never is a stronger
        // statement than the occurrence unique index makes: a second
        // occurrence key is still a second entry, and the shipped hourly sweep
        // presents a new key every hour, so a rule set to never re-entered the
        // same subject once an hour for as long as the condition held.
        // on_new_occurrence is exactly the index's own behaviour and needs
        // nothing here; always is expressed one level up by minting a repeat
        // key, which is why it is not a refusal either.
        if ($ruleId > 0 && $rule->reentry === ReentryMode::Never) {
            if ($this->runs->countForSubject($ruleId, $subjectType, $subjectId) >= 1) {
                return SuppressionReason::REENTRY_BLOCKED;
            }
        }

        if ($ruleId > 0 && $rule->timesPerSubject !== null) {
            if ($this->runs->countForSubject($ruleId, $subjectType, $subjectId) >= $rule->timesPerSubject) {
                return SuppressionReason::TIMES_PER_SUBJECT;
            }
        }

        if ($ruleId > 0 && $rule->totalCompletions !== null) {
            if ($this->runs->countForRule($ruleId) >= $rule->totalCompletions) {
                return SuppressionReason::TOTAL_COMPLETIONS;
            }
        }

        return null;
    }
}
