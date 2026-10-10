import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { components } from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { keepPrevious, must } from '@/shared/manage';

type Schemas = components['schemas'];

export type AutomationCatalogue = Schemas['AutomationCatalogue'];
export type AutomationActionDescriptor = Schemas['AutomationActionDescriptor'];
export type AutomationSentence = Schemas['AutomationSentence'];
export type AutomationRule = Schemas['AutomationRule'];
export type AutomationRuleAction = Schemas['AutomationRuleAction'];
export type AutomationRulePage = Schemas['AutomationRulePage'];
export type AutomationRuleRequest = Schemas['AutomationRuleRequest'];
export type AutomationRuleActionRequest = Schemas['AutomationRuleActionRequest'];
export type AutomationPreview = Schemas['AutomationPreview'];
export type AutomationRun = Schemas['AutomationRun'];
export type AutomationRunPage = Schemas['AutomationRunPage'];
export type AutomationActionLogEntry = Schemas['AutomationActionLogEntry'];
export type AutomationActionLogPage = Schemas['AutomationActionLogPage'];
export type AutomationRuleArchive = Schemas['AutomationRuleArchive'];

export interface AutomationRuleListParams {
    trigger_key?: string;
    state?: 'disabled' | 'enabled' | 'degraded';
    action_key?: string;
    search?: string;
    page?: number;
    per_page?: number;
}

export interface AutomationRunListParams {
    state?: 'pending_fanout' | 'running' | 'completed' | 'failed' | 'cancelled';
    dry_run?: boolean;
    page?: number;
    per_page?: number;
}

export const automationKeys = {
    root: ['vidyalms', 'admin', 'automations'] as const,
    catalogue: () => ['vidyalms', 'admin', 'automations', 'catalogue'] as const,
    lists: () => ['vidyalms', 'admin', 'automations', 'rules'] as const,
    list: (params: AutomationRuleListParams) => ['vidyalms', 'admin', 'automations', 'rules', params] as const,
    rule: (id: number) => ['vidyalms', 'admin', 'automations', 'rule', id] as const,
    runs: (id: number, params: AutomationRunListParams) =>
        ['vidyalms', 'admin', 'automations', 'runs', id, params] as const,
    actions: (id: number, runId: number, page: number) =>
        ['vidyalms', 'admin', 'automations', 'actions', id, runId, page] as const,
};

/** A fresh logical operation key. Callers keep it when they explicitly retry the same action. */
export function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `ui-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}

export function useAutomationCatalogue() {
    const client = useApiClient();
    return useQuery({
        queryKey: automationKeys.catalogue(),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/automations/catalogue', { signal });
            return must(data);
        },
    });
}

export function useAutomationRules(params: AutomationRuleListParams) {
    const client = useApiClient();
    return useQuery({
        queryKey: automationKeys.list(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/automation-rules', { params: { query: params }, signal });
            return must(data);
        },
    });
}

export function useAutomationRule(id: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: id !== null,
        queryKey: automationKeys.rule(id ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/automation-rules/{id}', {
                params: { path: { id: id as number } },
                signal,
            });
            return must(data);
        },
    });
}

function useRuleInvalidation() {
    const queryClient = useQueryClient();
    return (rule: AutomationRule) => {
        queryClient.setQueryData(automationKeys.rule(rule.id), rule);
        void queryClient.invalidateQueries({ queryKey: automationKeys.lists() });
    };
}

export function useCreateAutomationRule() {
    const client = useApiClient();
    const update = useRuleInvalidation();
    return useMutation({
        mutationFn: async ({ body, idempotencyKey }: { body: AutomationRuleRequest; idempotencyKey: string }) => {
            const { data } = await client.POST('/manage/automation-rules', {
                params: { header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
        onSuccess: update,
    });
}

export function useUpdateAutomationRule() {
    const client = useApiClient();
    const update = useRuleInvalidation();
    return useMutation({
        mutationFn: async ({
            id,
            body,
            idempotencyKey,
        }: {
            id: number;
            body: AutomationRuleRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.PATCH('/manage/automation-rules/{id}', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
        onSuccess: update,
    });
}

export function useArchiveAutomationRule() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, idempotencyKey }: { id: number; idempotencyKey: string }) => {
            const { data } = await client.DELETE('/manage/automation-rules/{id}', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: automationKeys.root });
        },
    });
}

export function useArmAutomationRule() {
    const client = useApiClient();
    const update = useRuleInvalidation();
    return useMutation({
        mutationFn: async ({
            id,
            expectedVersion,
            idempotencyKey,
        }: {
            id: number;
            expectedVersion: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/manage/automation-rules/{id}/arm', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                body: { expected_version: expectedVersion, acknowledgement: 'this_rule_may_act_on_people' },
            });
            return must(data);
        },
        onSuccess: update,
    });
}

export function useDisarmAutomationRule() {
    const client = useApiClient();
    const update = useRuleInvalidation();
    return useMutation({
        mutationFn: async ({
            id,
            expectedVersion,
            idempotencyKey,
        }: {
            id: number;
            expectedVersion: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/manage/automation-rules/{id}/disarm', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                body: { expected_version: expectedVersion },
            });
            return must(data);
        },
        onSuccess: update,
    });
}

export function usePreviewAutomationRule() {
    const client = useApiClient();
    return useMutation({
        mutationFn: async ({
            id,
            subjectIds,
            idempotencyKey,
        }: {
            id: number;
            subjectIds: number[];
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/manage/automation-rules/{id}/preview', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                body: { subject_ids: subjectIds },
            });
            return must(data);
        },
    });
}

export function useAutomationRuns(id: number | null, params: AutomationRunListParams) {
    const client = useApiClient();
    return useQuery({
        enabled: id !== null,
        queryKey: automationKeys.runs(id ?? 0, params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/automation-rules/{id}/runs', {
                params: { path: { id: id as number }, query: params },
                signal,
            });
            return must(data);
        },
    });
}

export function useAutomationRunActions(id: number | null, runId: number | null, page: number) {
    const client = useApiClient();
    return useQuery({
        enabled: id !== null && runId !== null,
        queryKey: automationKeys.actions(id ?? 0, runId ?? 0, page),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/automation-rules/{id}/runs/{run_id}/actions', {
                params: { path: { id: id as number, run_id: runId as number }, query: { page } },
                signal,
            });
            return must(data);
        },
    });
}
