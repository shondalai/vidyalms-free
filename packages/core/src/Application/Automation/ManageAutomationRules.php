<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityRuleValidator;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRuleState;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepDefinition;
use Shondalai\VidyaLMS\Core\Domain\Automation\StoredRuleTest;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleTestRepository;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresCost;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\RequiresApprovalBeforeEffect;

/**
 * Authoring, arming and disarming a rule.
 *
 * Arming is the constitutional act of this engine. The run executes as
 * Principal::user($rule->armedBy), so the audit names a person and never a
 * synthetic system user, and the arming stores a SHA-256 over the full ordered
 * action set **including params**, so an edit after arming is detectable: the
 * fan-out refuses a step whose digest no longer matches.
 *
 * **Any edit creates a new version and clears the arming triple**, so the rule
 * is inert until a person arms it again. The design named the two capabilities
 * that make an edit constitutional, and the shipped code narrowed the clearing
 * to those two while the assembler cleared half the triple on every edit
 * anyway: the result was a rule the API reported as enabled and armed that
 * could not open a run. Clearing the whole triple on every edit is the honest
 * form, because the digest covers the version and the version moves on every
 * save, so an arming that survived would name an act that no longer describes
 * what runs.
 */
final class ManageAutomationRules
{
    public function __construct(
        private readonly SqlAutomationRuleRepository $rules,
        private readonly SqlAutomationRuleTestRepository $tests,
        private readonly AutomationRuleValidator $validator,
        private readonly AutomationActionRegistry $actions,
        private readonly AccessGuard $access,
        /**
         * The reference rows, written under owner_type `automation_rule`.
         *
         * Optional so a harness without the availability spine still saves a
         * rule; absent, the rule stores and its references do not, which is
         * exactly the state the whole engine was in until R6.
         */
        private readonly ?SqlAvailabilityRuleRepository $availabilityRules = null,
        private readonly ?AvailabilityRuleValidator $availabilityValidator = null,
    ) {
    }

    /**
     * @return list<AutomationRule>
     */
    public function list(Principal $actor): array
    {
        $this->access->require($actor, Capability::MANAGE);

        return $this->rules->all();
    }

    public function get(Principal $actor, int $id): AutomationRule
    {
        $this->access->require($actor, Capability::MANAGE);
        $rule = $this->rules->find($id);

        if ($rule === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRule', $id);
        }

        return $rule;
    }

    /**
     * Saves a rule at its version, refusing every save-time problem by name.
     *
     * A version is a new row rather than an update whenever the previous
     * version was armed and the rule carries an action that needs re-arming,
     * because otherwise the arming record names an act that no longer
     * describes what will run.
     */
    public function save(Principal $actor, AutomationRule $rule): AutomationRule
    {
        $this->access->require($actor, Capability::MANAGE);

        // The version bump lives here and no longer in the HTTP mapper. It was
        // computed in AutomationRuleAdmin::assemble alone, so a save through
        // this use case by an importer, a course copy or the smoke contract
        // wrote at the version an in-flight run was executing, and
        // writeSteps() deletes and reinserts the rows for (rule_id,
        // rule_version): the run's own steps were rewritten underneath it.
        $rule = $this->atNextVersion($rule);
        $refusals = $this->validator->refusals($rule);

        if ($refusals !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException(['rule' => implode('; ', $refusals)], implode('; ', $refusals));
        }

        // The stored approval column, derived here rather than only on the
        // wire. It was set by AutomationRuleAdmin::needsApproval and by nothing
        // else, so a rule saved through this use case with an action declaring
        // RequiresApprovalBeforeEffect stored zero, the queue row went to
        // pending, and the effect ran with nobody approving. The validator's
        // own docblock names "the API, an importer or a course copy" as the
        // paths that must not differ.
        $rule = $this->withDeclaredApproval($rule);
        $id = $this->rules->save($rule, $actor->userId);
        $saved = $this->rules->find($id);

        if ($saved === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRule', $id);
        }

        // A registry miss is resolved at save and again at load, and it is a
        // rule-level condition rather than a step failure: the alternative
        // lets the free half of a rule keep running while an access revocation
        // silently never happens.
        $state = $this->missingKeys($saved) === []
            ? $saved->state
            : AutomationRuleState::Degraded;

        if ($state !== $saved->state) {
            $this->rules->markState($id, $state);
        }

        // Any edit to an armed rule clears the whole arming triple and puts
        // the rule back to disabled.
        //
        // The half-cleared triple was the defect: the assembler passed
        // active = false and omitted armed_at and armed_digest while
        // preserving armed_by, the repository wrote all four, and the result
        // was a rule the API reported as enabled and armed that could no
        // longer open a single run, with nothing anywhere telling the
        // operator. It is cleared whole rather than re-stamped because the
        // digest covers the version and the version has just moved: a
        // re-stamped digest would record the new content under the old
        // person's name, which is the audit gap the arming columns exist to
        // close.
        if ($saved->armedBy !== null) {
            $this->rules->disarm($id);
        }

        $this->writeReferences($id, $saved);

        return $this->rules->find($id) ?? $saved;
    }

    /**
     * The leaf reference rows of this rule's entry tree and every step's guard
     * tree, under owner_type `automation_rule`.
     *
     * tables.php added the fifth owner_type with the argument that it is what
     * makes "every rule that references lesson 88" an indexed query and what
     * makes a course copy remap an automation's references through the
     * identical save-time pass. No file under Application/Automation or
     * Persistence/Automation touched the table, so the value existed and the
     * rows did not: a rule whose entry tree named a lesson survived a course
     * copy still pointing at the old course's lesson.
     *
     * One call, with the guard trees' leaf paths prefixed by their step, so
     * the rows of one rule are replaced together rather than each tree
     * deleting the last one's.
     */
    private function writeReferences(int $ruleId, AutomationRule $rule): void
    {
        if ($this->availabilityRules === null || $this->availabilityValidator === null) {
            return;
        }

        $references = [];

        if ($rule->conditions !== null && $rule->conditions->root !== null) {
            $report = $this->availabilityValidator->validate(
                $rule->conditions,
                AvailabilitySurface::Automation,
                null,
                $rule->courseId,
            );

            foreach ($report->references as $reference) {
                $references[] = $reference;
            }
        }

        foreach ($rule->steps as $step) {
            if ($step->guardConditions === null || $step->guardConditions->root === null) {
                continue;
            }

            $report = $this->availabilityValidator->validate(
                $step->guardConditions,
                AvailabilitySurface::Automation,
                null,
                $rule->courseId,
            );

            foreach ($report->references as $reference) {
                $reference['leaf_path'] = 'step.' . $step->stepIndex . '.' . $reference['leaf_path'];
                $references[] = $reference;
            }
        }

        $this->availabilityRules->replaceReferences(
            SqlAvailabilityRuleRepository::OWNER_AUTOMATION_RULE,
            $ruleId,
            AvailabilitySurface::Automation,
            $references,
        );
    }

    /**
     * An existing rule is saved at the next version; a new one starts at the
     * version it was authored with.
     */
    private function atNextVersion(AutomationRule $rule): AutomationRule
    {
        if ($rule->id === null) {
            return $rule;
        }

        $current = $this->rules->find($rule->id);

        if ($current === null || $rule->version > $current->version) {
            return $rule;
        }

        return self::copyWith($rule, $current->version + 1, $rule->steps);
    }

    private function withDeclaredApproval(AutomationRule $rule): AutomationRule
    {
        $steps = [];
        $changed = false;

        foreach ($rule->steps as $step) {
            $declared = $this->actions->has($step->actionKey)
                && $this->actions->get($step->actionKey) instanceof RequiresApprovalBeforeEffect;

            if (!$declared || $step->requiresApprovalBeforeEffect) {
                $steps[] = $step;

                continue;
            }

            $changed = true;
            $steps[] = new AutomationStepDefinition(
                $step->stepIndex,
                $step->actionKey,
                $step->params,
                $step->paramsVersion,
                $step->guardConditions,
                $step->delayAnchorKey,
                $step->delay,
                $step->retryPolicyKey,
                $step->failurePolicyKey,
                true,
                $step->producesReviewItem,
                $step->armedPromptRef,
                $step->ownerUserId,
                $step->delayVersion,
            );
        }

        return $changed ? self::copyWith($rule, $rule->version, $steps) : $rule;
    }

    /**
     * @param list<AutomationStepDefinition> $steps
     */
    private static function copyWith(AutomationRule $rule, int $version, array $steps): AutomationRule
    {
        return new AutomationRule(
            id: $rule->id,
            ruleKey: $rule->ruleKey,
            title: $rule->title,
            triggerKey: $rule->triggerKey,
            conditions: $rule->conditions,
            steps: $steps,
            reentry: $rule->reentry,
            timesPerSubject: $rule->timesPerSubject,
            totalCompletions: $rule->totalCompletions,
            cooldownSeconds: $rule->cooldownSeconds,
            runOrder: $rule->runOrder,
            version: $version,
            active: $rule->active,
            state: $rule->state,
            subjectBinding: $rule->subjectBinding,
            scopeType: $rule->scopeType,
            scopeId: $rule->scopeId,
            courseId: $rule->courseId,
            cohortId: $rule->cohortId,
            organisationId: $rule->organisationId,
            scheduleKind: $rule->scheduleKind,
            anchorKey: $rule->anchorKey,
            armedBy: $rule->armedBy,
            armedAt: $rule->armedAt,
            armedDigest: $rule->armedDigest,
            maxAiRunsPerOccurrence: $rule->maxAiRunsPerOccurrence,
            maxAiRunsPerArming: $rule->maxAiRunsPerArming,
            createdBy: $rule->createdBy,
            lastDryRunAt: $rule->lastDryRunAt,
            queuedCount: $rule->queuedCount,
            executedCount: $rule->executedCount,
            suppressedCount: $rule->suppressedCount,
            failedCount: $rule->failedCount,
        );
    }

    /**
     * Arms a rule, recording who and when and the digest of exactly what.
     *
     * The digest covers the full ordered action set including params, because
     * the params are where an author's instruction lives and an edit after
     * arming would otherwise change what every future subject gets while the
     * arming record still named the original act.
     */
    public function arm(Principal $actor, int $id): AutomationRule
    {
        $this->access->require($actor, Capability::MANAGE);
        $rule = $this->get($actor, $id);

        $refusals = $this->validator->refusals($rule);

        if ($refusals !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException(['rule' => implode('; ', $refusals)], implode('; ', $refusals));
        }

        if ($this->missingKeys($rule) !== []) {
            throw new ValidationException(
                ['state' => 'automation.cannot_arm_degraded_rule'],
                'automation.cannot_arm_degraded_rule',
            );
        }

        $this->rules->arm($id, $actor->userId, self::armingDigest($rule));
        $this->rules->activateVersion($id, $rule->version);

        return $this->rules->find($id) ?? $rule;
    }

    public function disarm(Principal $actor, int $id): AutomationRule
    {
        $this->access->require($actor, Capability::MANAGE);
        $rule = $this->get($actor, $id);
        $this->rules->disarm($id);

        return $this->rules->find($id) ?? $rule;
    }

    /**
     * Whether the rule that is stored still matches what was armed.
     *
     * The fan-out asks this before it enqueues any step, which is what makes
     * the digest a control rather than a record. It had no caller anywhere
     * while three separate docblocks and the schema comment all said the
     * fan-out refuses a step whose digest no longer matches, so the column
     * existed and enforced nothing.
     *
     * It is static because the fan-out is constructed before this use case is
     * and the answer depends on nothing but the rule.
     */
    public static function armingStillHolds(AutomationRule $rule): bool
    {
        if ($rule->armedDigest === null) {
            return false;
        }

        return hash_equals($rule->armedDigest, self::armingDigest($rule));
    }

    public function saveTest(Principal $actor, StoredRuleTest $test): StoredRuleTest
    {
        $this->access->require($actor, Capability::MANAGE);
        $id = $this->tests->save($test);

        return $this->tests->find($id) ?? $test;
    }

    /**
     * @return list<StoredRuleTest>
     */
    public function testsFor(Principal $actor, int $ruleId): array
    {
        $this->access->require($actor, Capability::MANAGE);

        return $this->tests->forRule($ruleId);
    }

    /**
     * The SHA-256 over the full ordered action set including params.
     *
     * Composed here so the arm path and the check path cannot spell it
     * differently, which is the failure mode a digest computed in two places
     * always eventually has.
     */
    public static function armingDigest(AutomationRule $rule): string
    {
        $parts = [];

        foreach ($rule->steps as $step) {
            $params = $step->params;
            ksort($params);

            $parts[] = implode('|', [
                (string) $step->stepIndex,
                $step->actionKey,
                (string) $step->paramsVersion,
                json_encode($params, \JSON_THROW_ON_ERROR),
                $step->retryPolicyKey,
                $step->failurePolicyKey,
                $step->delayAnchorKey ?? '',
            ]);
        }

        return hash('sha256', $rule->ruleKey . '|' . $rule->version . '|' . implode("\n", $parts));
    }

    /**
     * @return list<string>
     */
    private function missingKeys(AutomationRule $rule): array
    {
        $missing = [];

        foreach ($rule->steps as $step) {
            if (!$this->actions->has($step->actionKey)) {
                $missing[] = $step->actionKey;
            }
        }

        return $missing;
    }

    /**
     * Whether this rule carries an action whose edit is the constitutional
     * kind, which is now a statement the run log makes rather than the switch
     * that decides whether the arming is cleared.
     *
     * Every edit clears the arming, because every edit bumps the version and
     * the digest covers the version. This stays because the review screen says
     * which edits were the ones the design singles out.
     */
    public function needsReArming(AutomationRule $rule): bool
    {
        foreach ($rule->steps as $step) {
            if (!$this->actions->has($step->actionKey)) {
                continue;
            }

            $action = $this->actions->get($step->actionKey);

            if ($action instanceof RequiresApprovalBeforeEffect || $action instanceof DeclaresCost) {
                return true;
            }
        }

        return false;
    }
}
