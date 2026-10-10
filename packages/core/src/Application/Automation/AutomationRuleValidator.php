<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepDefinition;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresCost;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\EmitsOccurrences;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\RequiresApprovalBeforeEffect;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Automation\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\DeclaresSubjectTypes;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\EventTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\SweepTriggerInterface;

/**
 * Everything a rule is refused for at save time.
 *
 * Save time rather than run time, everywhere, and for one reason: a rule that
 * reaches the table through the API, an importer or a course copy and fails
 * only when it fires is a rule whose failure nobody sees until it has already
 * not done something.
 *
 * The inert pairing check is the named n8n bug: enabling Retry On Fail while
 * On Error is set to a continue value makes Max Tries and Wait Between Tries
 * dead settings and the node continues on the first failure. Exposing both
 * axes obliges the product to validate their interaction rather than letting
 * one silently cancel the other, and the check reads **two registries** rather
 * than two hardcoded lists, which is why both policies are seams.
 */
final class AutomationRuleValidator
{
    /**
     * Actions that could publish, mark, gate or issue, kept as a redundant net
     * and no longer as the authority.
     *
     * It used to be the authority for whether the check ran at all, so
     * `grant_availability_probe` was caught by the letters in its name while
     * `enrol`, `unenrol` and a third party's `acme.unlock_module` walked past
     * it, and any non-English key escaped by construction. The refusal now
     * turns on DeclaresEffectClass, which the action states about itself, and
     * these seven words only catch an action that declares nothing at all.
     */
    public const EFFECT_WORDS = ['publish', 'grant', 'revoke', 'issue', 'mark', 'grade', 'complete'];

    /**
     * The namespace an automation-emitted event key lives in.
     *
     * A rule may sit on a registered trigger or on a key another rule emits,
     * and the second kind has no provider to resolve, so the save-time check
     * needs a way to tell an emitted key from a typo. Reserving a prefix is
     * that way: everything else must be a registered trigger, and a rule on a
     * key nobody answers and nobody could emit is refused rather than stored
     * and armed with no path to a run.
     */
    public const EMITTED_TRIGGER_PREFIX = 'automation.event.';

    public function __construct(
        private readonly AutomationActionRegistry $actions,
        private readonly RetryPolicyRegistry $retries,
        private readonly FailurePolicyRegistry $failures,
        private readonly ?DatabasePort $database = null,
        /**
         * The trigger registry, so the check that a rule names a runnable
         * trigger happens on every save path.
         *
         * It lived in the HTTP assembler alone, so a package, an importer or a
         * course copy saved and armed a rule on `no.such.trigger.anywhere`
         * without a murmur. This class's own docblock says save time
         * everywhere, and for exactly that reason.
         */
        private readonly ?NotificationTriggerRegistry $triggers = null,
    ) {
    }

    /**
     * The refusals, empty when the rule may be saved.
     *
     * @return list<string>
     */
    public function refusals(AutomationRule $rule): array
    {
        $refusals = [];

        if ($rule->ruleKey === '') {
            $refusals[] = 'automation.rule_key_required';
        }

        if ($rule->triggerKey === '') {
            $refusals[] = 'automation.trigger_key_required';
        } else {
            foreach ($this->triggerRefusals($rule->triggerKey) as $refusal) {
                $refusals[] = $refusal;
            }
        }

        // The scope columns restricted nothing: organisation_id, cohort_id,
        // scope_type and scope_id were accepted, stored and presented while
        // the only reads of organisationId in the engine copied it onto a
        // context nothing consumed, so a rule scoped to one organisation acted
        // on a learner who belonged to none. They are refused until the
        // tenancy read that would enforce them lands, because an operator who
        // can set a scope the engine ignores is worse off than one who cannot
        // set it at all. course_id is not here: the fan-out enforces it.
        if ($rule->organisationId !== null) {
            $refusals[] = 'automation.organisation_scope_not_enforced_yet';
        }

        if ($rule->cohortId !== null) {
            $refusals[] = 'automation.cohort_scope_not_enforced_yet';
        }

        if ($rule->scopeType !== 'site' && $rule->scopeType !== 'course') {
            $refusals[] = 'automation.scope_type_not_enforced_yet:' . $rule->scopeType;
        }

        if ($rule->steps === []) {
            $refusals[] = 'automation.at_least_one_step_required';
        }

        $seen = [];

        foreach ($rule->steps as $step) {
            if (\in_array($step->stepIndex, $seen, true)) {
                $refusals[] = 'automation.duplicate_step_index:' . $step->stepIndex;
            }

            $seen[] = $step->stepIndex;

            foreach ($this->stepRefusals($rule, $step) as $refusal) {
                $refusals[] = $refusal;
            }
        }

        // Every token the rule writes must be one its trigger supplies. The
        // triggers publish declaredPlaceholders() and nothing checked a rule
        // against them, so the design's "fails validation and cannot be armed"
        // had no implementation and a step referencing {{cohort_name}} behind
        // an enrolment trigger saved, armed and rendered the literal braces.
        foreach ($this->placeholderRefusals($rule) as $refusal) {
            $refusals[] = $refusal;
        }

        // Loop protection three, refused rather than defaulted: a rule whose
        // action set contains an emitting action and whose cooldown is zero is
        // the two-rule cycle with one protection switched off.
        if ($rule->cooldownSeconds < 1 && $this->emitsEvents($rule)) {
            $refusals[] = 'automation.emit_event_requires_cooldown';
        }

        return $refusals;
    }

    /**
     * @return list<string>
     */
    private function stepRefusals(AutomationRule $rule, AutomationStepDefinition $step): array
    {
        $refusals = [];

        if (!$this->actions->has($step->actionKey)) {
            // Not a refusal to save. A registry miss is a rule-level condition
            // resolved to `degraded` at save and again at load, because a
            // refusal here would make removing a package unable to save the
            // rule that named it.
            return $refusals;
        }

        $action = $this->actions->get($step->actionKey);

        if (!$action instanceof AutomationActionInterface) {
            return $refusals;
        }

        if (!$action instanceof SupportsDryRun) {
            // Mandatory for the builder, which is a deliberate departure from
            // the products whose answer to the absence of a rehearsal is to
            // advise a staging site.
            $refusals[] = 'automation.action_must_support_dry_run:' . $step->actionKey;
        }

        // The design's sentence made mechanical: an action that touches marks,
        // access or credentials may run only as far as a proposed change
        // parked for a named person. The action says which of those it is, so
        // a third-party action that gates content is caught by its own
        // declaration and not by whether its key happens to contain an English
        // word. The seven words stay as a net over an action that declares
        // nothing at all, and an action that will not classify itself must
        // accept a person in the loop instead.
        if (!$action instanceof RequiresApprovalBeforeEffect) {
            if ($action instanceof DeclaresEffectClass) {
                if ($action->effectClass()->requiresApproval()) {
                    $refusals[] = 'automation.action_must_require_approval:' . $step->actionKey;
                }
            } elseif ($this->looksLikeAnEffect($step->actionKey)) {
                $refusals[] = 'automation.action_must_require_approval:' . $step->actionKey;
            } else {
                $refusals[] = 'automation.action_must_declare_effect_class:' . $step->actionKey;
            }
        }

        // The applicability the seam publishes, enforced. It declared
        // `subject_types` and `trigger_keys` and promised a save-time refusal,
        // every shipped provider answered two different keys, and nothing read
        // either: this is the enforcement that would have caught a notify step
        // behind a course-subjected trigger at save rather than at the mail.
        foreach ($this->applicabilityRefusals($rule, $step, $action) as $refusal) {
            $refusals[] = $refusal;
        }

        if ($this->retries->has($step->retryPolicyKey) && $this->failures->has($step->failurePolicyKey)) {
            $plan = $this->retries->get($step->retryPolicyKey)->plan();
            $disposition = $this->failures->get($step->failurePolicyKey)->disposition();

            if ($plan->retries() && $disposition->cancelsRetries()) {
                $refusals[] = sprintf(
                    'automation.inert_retry_pairing:%s+%s',
                    $step->retryPolicyKey,
                    $step->failurePolicyKey,
                );
            }
        }

        if ($action instanceof SupportsPayloadVersions) {
            if (!\in_array($step->paramsVersion, $action->supportedParamsVersions(), true)) {
                $refusals[] = 'automation.unsupported_params_version:' . $step->actionKey;
            }
        } elseif ($step->paramsVersion !== 1) {
            $refusals[] = 'automation.unsupported_params_version:' . $step->actionKey;
        }

        if ($action instanceof ValidatesPayload && $this->database !== null) {
            $context = PayloadValidationContext::create(
                $this->database,
                AvailabilitySurface::Automation,
                null,
                'step.' . $step->stepIndex,
                $rule->courseId,
            );

            foreach ($action->validateParams($step->params, $context) as $problem) {
                $refusals[] = $problem;
            }
        }

        if ($action instanceof DeclaresCost) {
            // Both caps are refused when null on a rule carrying a costed
            // action, so an unbounded fan-out cannot be armed by one human act.
            if ($rule->maxAiRunsPerOccurrence === null || $rule->maxAiRunsPerArming === null) {
                $refusals[] = 'automation.costed_action_requires_caps:' . $step->actionKey;
            }
        }

        return $refusals;
    }

    /**
     * Every `{{token}}` any step's params carry that the rule's trigger does
     * not declare.
     *
     * A trigger with no declared placeholders restricts nothing, which is the
     * capability convention: the check says something only when both halves
     * have declared.
     *
     * @return list<string>
     */
    private function placeholderRefusals(AutomationRule $rule): array
    {
        if ($this->triggers === null || !$this->triggers->has($rule->triggerKey)) {
            return [];
        }

        $declared = array_map(
            static fn (mixed $name): string => (string) $name,
            $this->triggers->get($rule->triggerKey)->declaredPlaceholders(),
        );

        if ($declared === []) {
            return [];
        }

        $refusals = [];

        foreach ($rule->steps as $step) {
            foreach (self::tokensIn($step->params) as $token) {
                if (!\in_array($token, $declared, true)) {
                    $refusals[] = 'automation.unknown_placeholder:' . $token;
                }
            }
        }

        return array_values(array_unique($refusals));
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<string>
     */
    private static function tokensIn(array $params): array
    {
        $tokens = [];

        foreach ($params as $value) {
            if (\is_array($value)) {
                foreach (self::tokensIn($value) as $token) {
                    $tokens[] = $token;
                }

                continue;
            }

            if (!\is_string($value)) {
                continue;
            }

            if (preg_match_all('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', $value, $matches) > 0) {
                foreach ($matches[1] as $token) {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /**
     * The two axes an action may restrict itself along, checked against the
     * rule that names it.
     *
     * @return list<string>
     */
    private function applicabilityRefusals(
        AutomationRule $rule,
        AutomationStepDefinition $step,
        AutomationActionInterface $action,
    ): array {
        $refusals = [];
        $declared = $action->applicability();

        $triggerKeys = $declared['trigger_keys'] ?? [];

        if ($triggerKeys !== [] && !\in_array($rule->triggerKey, $triggerKeys, true)) {
            $refusals[] = 'automation.action_not_applicable_to_trigger:' . $step->actionKey;
        }

        $subjectTypes = $declared['subject_types'] ?? [];
        $yielded = $this->triggerSubjectTypes($rule->triggerKey);

        // Both sides have to declare for this to say anything, which is the
        // capability convention: an absent declaration is no restriction of
        // that kind rather than a refusal of everything.
        if ($subjectTypes !== [] && $yielded !== [] && array_intersect($subjectTypes, $yielded) === []) {
            $refusals[] = 'automation.action_not_applicable_to_subject:' . $step->actionKey;
        }

        return $refusals;
    }

    /**
     * A trigger this site cannot fan out from is refused at save.
     *
     * A missing action key leaves the rule degraded on purpose, because the
     * package that answers it can be reinstalled and the authored intent
     * survives. A trigger is different in kind: it is the rule's only entry
     * point, so a rule on an unresolvable trigger has no path to a run at all,
     * and one that resolves to neither an event trigger nor a sweep trigger
     * declares no subject for the fan-out to act on.
     *
     * @return list<string>
     */
    private function triggerRefusals(string $triggerKey): array
    {
        if ($this->triggers === null) {
            return [];
        }

        if (!$this->triggers->has($triggerKey)) {
            // An emitted key has no provider by construction, so the reserved
            // namespace is what tells one from a typo.
            return str_starts_with($triggerKey, self::EMITTED_TRIGGER_PREFIX)
                ? []
                : ['automation.unknown_trigger:' . $triggerKey];
        }

        $trigger = $this->triggers->get($triggerKey);

        return $trigger instanceof EventTriggerInterface || $trigger instanceof SweepTriggerInterface
            ? []
            : ['automation.trigger_resolves_no_subject:' . $triggerKey];
    }

    /**
     * The subject types this rule's trigger can actually yield, or the empty
     * list when it declares none.
     *
     * @return list<string>
     */
    private function triggerSubjectTypes(string $triggerKey): array
    {
        if ($this->triggers === null || !$this->triggers->has($triggerKey)) {
            return [];
        }

        $trigger = $this->triggers->get($triggerKey);

        return $trigger instanceof DeclaresSubjectTypes ? $trigger->subjectTypes() : [];
    }

    /**
     * Whether any action on this rule opens automation runs of its own.
     *
     * It was a substring scan for the literal key `emit_event`, so a
     * third-party action calling the same public opener simply walked past the
     * cooldown-zero refusal by being called something else: two mutually
     * emitting rules at cooldown zero opened one run per turn against a
     * ceiling of five and never approached it.
     */
    private function emitsEvents(AutomationRule $rule): bool
    {
        foreach ($rule->actionKeys() as $key) {
            if (!$this->actions->has($key)) {
                continue;
            }

            if ($this->actions->get($key) instanceof EmitsOccurrences) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeAnEffect(string $actionKey): bool
    {
        foreach (self::EFFECT_WORDS as $word) {
            if (str_contains($actionKey, $word)) {
                return true;
            }
        }

        return false;
    }
}
