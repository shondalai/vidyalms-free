<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One thing an automation rule can do when it fires.
 *
 * The action key is stored on automation_rule_actions, so this is a registry
 * and not a const list: the alternative is a hardcoded vocabulary that makes
 * every new action a core edit, which is the failure the registries exist to
 * prevent.
 *
 * **idempotencyKeyFor() is deliberately absent, and its absence is the fix
 * for a silent-loss bug.** An earlier draft put it here with no constraint on
 * what a provider might return, so an action answering a constant, or a key
 * omitting the subject id, claims one idempotency_keys row under the unique
 * (scope, idem_key) and the runner replays the first outcome verbatim: a
 * fan-out of four hundred enrolments executes one and records 399 replays as
 * executed, each with its own action-log row and success = 1. The failure
 * reads as a clean run. The canonical digest is computed by the core and
 * carried on AutomationActionContext, and an action needing a finer grain
 * declares DeclaresIdempotencyDiscriminator, whose string the core appends
 * rather than substitutes.
 *
 * Four methods and no more. Everything optional is a capability interface
 * probed with instanceof, each publishing what its absence means, and three
 * of the nine share a name with an availability namesake on purpose.
 */
interface AutomationActionInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The authored params, as a schema the form renders and the core
     * interprets not at all, exactly as a condition's params schema is.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array;

    /**
     * Where this action may be used, so a rule naming it outside its
     * declared applicability is a save-time refusal rather than a run-time
     * mismatch, in the same shape a condition leaf used outside its own
     * already is.
     *
     * Two keys, both optional and both absent meaning "no restriction of
     * this kind": `subject_types` is the list of subject type words the
     * action can act on (`user`, `enrolment`, `organisation`), and
     * `trigger_keys` is the list of trigger provider keys it may sit behind
     * when it only makes sense after one of them. It is a map rather than
     * the availability namesake's list because an action restricts along two
     * axes and a condition restricts along one.
     *
     * @return array{subject_types?: list<string>, trigger_keys?: list<string>}
     */
    public function applicability(): array;

    /**
     * Do the thing, once, for one subject.
     *
     * The context carries the dry-run flag, so one code path serves the
     * preview and the real run, which is what stops the preview drifting from
     * reality.
     */
    public function execute(AutomationActionContext $context): ActionOutcome;
}
