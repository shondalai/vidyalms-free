import type { AutomationCatalogue, AutomationRule, AutomationRuleActionRequest, AutomationRuleRequest } from './api';
import type { ConditionTree } from './conditions';
import { starterFor } from './workspace';

export interface ActionDraft {
    actionKey: string;
    params: Record<string, unknown>;
    retryPolicyKey: string;
    failurePolicyKey: string;
    delayAnchorKey: string;
    delay: Record<string, unknown>;
    guard: ConditionTree | null;
    owner: number | null;
    paramsVersion: number;
    delayVersion: number;
    guardVersion: number;
}
export interface Draft {
    title: string;
    ruleKey: string;
    triggerKey: string;
    conditions: ConditionTree | null;
    conditionsVersion: number;
    actions: ActionDraft[];
    reentry: 'never' | 'on_new_occurrence' | 'always';
    cooldownSeconds: number;
    runOrder: number;
    subjectBinding: 'learner' | 'instructor' | 'course' | 'cohort' | 'organisation';
    timesPerSubject: number | null;
    totalCompletions: number | null;
    courseId: number | null;
    maxAiOccurrence: number | null;
    maxAiArming: number | null;
}
export function newAction(actionKey: string, catalogue: AutomationCatalogue): ActionDraft {
    return {
        actionKey,
        params: {},
        retryPolicyKey:
            catalogue.retry_policies.find((entry) => entry.max_attempts === 1)?.key ??
            catalogue.retry_policies[0]?.key ??
            '',
        failurePolicyKey:
            catalogue.failure_policies.find((entry) => entry.behaviour === 'park')?.key ??
            catalogue.failure_policies[0]?.key ??
            '',
        delayAnchorKey: '',
        delay: {},
        guard: null,
        owner: null,
        paramsVersion: 1,
        delayVersion: 1,
        guardVersion: 1,
    };
}
export function draftFrom(
    rule: AutomationRule | undefined,
    catalogue: AutomationCatalogue | undefined,
    starter?: string,
): Draft {
    if (!rule) {
        const template = catalogue && starterFor(starter, catalogue);
        return {
            title: template?.title ?? '',
            ruleKey: '',
            triggerKey: template?.triggerKey ?? '',
            conditions: null,
            conditionsVersion: 1,
            actions: template && catalogue ? [newAction(template.actionKey, catalogue)] : [],
            reentry: 'never',
            cooldownSeconds: catalogue?.limits.cooldown_seconds_default ?? 300,
            runOrder: 100,
            subjectBinding: 'learner',
            timesPerSubject: null,
            totalCompletions: null,
            courseId: null,
            maxAiOccurrence: null,
            maxAiArming: null,
        };
    }
    return {
        title: rule.title,
        ruleKey: rule.rule_key,
        triggerKey: rule.trigger_key,
        conditions: rule.conditions ?? null,
        conditionsVersion: rule.conditions_version,
        actions: rule.actions.map((action) => ({
            actionKey: action.action_key,
            params: { ...(action.params ?? {}) },
            retryPolicyKey: action.retry_policy_key,
            failurePolicyKey: action.failure_policy_key,
            delayAnchorKey: action.delay_anchor_key ?? '',
            delay: { ...(action.delay ?? {}) },
            guard: action.guard_conditions ?? null,
            owner: action.owner_user_id ?? null,
            paramsVersion: action.params_version,
            delayVersion: action.delay_version ?? 1,
            guardVersion: action.guard_conditions_version ?? 1,
        })),
        reentry: rule.reentry,
        cooldownSeconds: rule.cooldown_seconds,
        runOrder: rule.run_order,
        subjectBinding: rule.subject_binding,
        timesPerSubject: rule.times_per_subject ?? null,
        totalCompletions: rule.total_completions ?? null,
        courseId: rule.course_id ?? null,
        maxAiOccurrence: rule.max_ai_runs_per_occurrence ?? null,
        maxAiArming: rule.max_ai_runs_per_arming ?? null,
    };
}
export function bodyOf(draft: Draft, expectedVersion: number | null): AutomationRuleRequest {
    const actions: AutomationRuleActionRequest[] = draft.actions.map((action) => ({
        action_key: action.actionKey,
        params: Object.keys(action.params).length ? action.params : null,
        params_version: action.paramsVersion,
        guard_conditions: action.guard,
        guard_conditions_version: action.guardVersion,
        retry_policy_key: action.retryPolicyKey || undefined,
        failure_policy_key: action.failurePolicyKey || undefined,
        delay_anchor_key: action.delayAnchorKey || null,
        delay: action.delayAnchorKey ? action.delay : null,
        delay_version: action.delayVersion,
        owner_user_id: action.owner,
    }));
    return {
        title: draft.title.trim(),
        ...(draft.ruleKey ? { rule_key: draft.ruleKey } : {}),
        trigger_key: draft.triggerKey,
        conditions: draft.conditions,
        conditions_version: draft.conditionsVersion,
        reentry: draft.reentry,
        cooldown_seconds: draft.cooldownSeconds,
        run_order: draft.runOrder,
        subject_binding: draft.subjectBinding,
        times_per_subject: draft.timesPerSubject,
        total_completions: draft.totalCompletions,
        course_id: draft.courseId,
        max_ai_runs_per_occurrence: draft.maxAiOccurrence,
        max_ai_runs_per_arming: draft.maxAiArming,
        actions,
        ...(expectedVersion === null ? {} : { expected_version: expectedVersion }),
    };
}
