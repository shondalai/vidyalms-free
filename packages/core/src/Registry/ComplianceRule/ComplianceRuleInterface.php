<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ComplianceRule;

use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceState;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceSubject;
use Shondalai\VidyaLMS\Core\Registry\Context\ComplianceContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * When an assignment falls due, and when it comes round again.
 *
 * The one seam the free build deliberately registers nothing into. The
 * free path that exercises it is the assignment itself, which records a
 * rule key of none and is rendered as assigned and untracked. A free rule
 * that guessed a due date would be worse than no rule at all.
 *
 * Miss direction: fail closed: never report compliant.
 *
 * The shape is completed here rather than in the wave that seated the seam,
 * under the rule that seating stated: nothing implemented it, so it could still
 * be corrected. **After the first provider ships it may never gain a method**,
 * and no provider ships in the free build, so the first Pro rule is what closes
 * it for good.
 *
 * **Compliance is a separate seam from availability.** Availability answers may
 * this person open this now; compliance answers must this person have completed
 * this by when, and when must they do it again. Collapsing them would make a
 * missed deadline into a locked door, which is neither what a mandatory training
 * record means nor what GDPR Article 22 permits an automated decision to do.
 *
 * ## Both answers are walked one edge at a time
 *
 * `compliance_status` and `renewal_status` are two published state machines and
 * the sweep applies each one's `permits()` to what a rule answers. So a rule
 * answers **the next word**, not the final one: `not_due -> due -> window_open`
 * is the renewal walk, and a rule that computes its window from the expiry and
 * jumps straight to `window_open` has that word refused. Read
 * {@see ComplianceSubject::$renewalStatus} to see where the row is standing and
 * answer from there; the three shipped recurring rules do exactly that, which
 * is why they have a notice band at all.
 *
 * A refused word used to discard the whole write, so a rule that skipped a band
 * lost its accepted compliance status with it, on every run, silently. The two
 * axes are now applied independently and the sweep summary names which one
 * refused, so a rule that is wrong about one is not silenced on the other.
 */
interface ComplianceRuleInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * JSON Schema 2020-12 for `compliance_requirements.rule_params`: what an
     * author may set, validated at save rather than at 03:00 in the sweep.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array;

    /**
     * The version this provider writes `rule_params` and `rule_state` in.
     *
     * Both columns mirror it, so a provider that has moved on can tell a row it
     * wrote from a row an earlier version of itself wrote, which is the whole
     * point of the {@see \Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape}
     * envelope applied to a seam whose payloads outlive their writer.
     */
    public function currentShape(): int;

    /**
     * This person's position against this requirement, right now.
     *
     * The rule owns its own per-assignment memory through
     * {@see ComplianceState::$ruleState}: `recurring-later-of` needs to remember
     * which anchor won last cycle, `recurring-fixed-anniversary` needs the
     * anniversary basis, and a third-party rule needs whatever it tracks. That
     * memory is never read by a report and never joined on.
     */
    public function evaluate(ComplianceSubject $subject, ComplianceContext $context): ComplianceState;
}
