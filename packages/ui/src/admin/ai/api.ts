import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { components } from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { keepPrevious, must } from '@/shared/manage';

type Schemas = components['schemas'];

export type AiEnablement = Schemas['AiEnablement'];
export type AiEnablementRequest = Schemas['AiEnablementRequest'];
export type AiStopState = Schemas['AiStopState'];
export type AiPolicyDocument = Schemas['AiPolicyDocument'];
export type AiPolicyPage = Schemas['AiPolicyPage'];
export type AiPolicyRequest = Schemas['AiPolicyRequest'];
export type AiRoute = Schemas['AiRoute'];
export type AiRoutePage = Schemas['AiRoutePage'];
export type AiRun = Schemas['AiRun'];
export type AiRunPage = Schemas['AiRunPage'];
export type AiDraft = Schemas['AiDraft'];
export type AiDraftPage = Schemas['AiDraftPage'];
export type AiUsageReport = Schemas['AiUsageReport'];

export interface AiRunListParams {
    state?: 'reserved' | 'running' | 'succeeded' | 'failed' | 'refused' | 'previewed' | 'cancelled';
    dry_run?: boolean;
    page?: number;
    per_page?: number;
}

export interface AiDraftListParams {
    state?: 'requested' | 'generated' | 'checked' | 'in_review' | 'accepted' | 'discarded';
    draft_kind?: string;
    page?: number;
    per_page?: number;
}

export const aiKeys = {
    root: ['vidyalms', 'admin', 'ai'] as const,
    enablement: () => ['vidyalms', 'admin', 'ai', 'enablement'] as const,
    policies: () => ['vidyalms', 'admin', 'ai', 'policies'] as const,
    routes: () => ['vidyalms', 'admin', 'ai', 'routes'] as const,
    runs: (params: AiRunListParams) => ['vidyalms', 'admin', 'ai', 'runs', params] as const,
    run: (id: number) => ['vidyalms', 'admin', 'ai', 'run', id] as const,
    drafts: (params: AiDraftListParams) => ['vidyalms', 'admin', 'ai', 'drafts', params] as const,
    usage: () => ['vidyalms', 'admin', 'ai', 'usage'] as const,
};

/** A fresh logical operation key. Callers keep it when they retry the same act. */
export function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `ui-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}

export function useAiEnablement() {
    const client = useApiClient();
    return useQuery({
        queryKey: aiKeys.enablement(),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/ai/enablement', { signal });
            return must(data);
        },
    });
}

export function useSetAiEnablement() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            body,
            idempotencyKey,
        }: {
            body: AiEnablementRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.PUT('/manage/ai/enablement', {
                params: { header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
        onSuccess: (enablement) => {
            queryClient.setQueryData(aiKeys.enablement(), enablement);
        },
    });
}

export function useSetAiStop() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ reason, idempotencyKey }: { reason?: string; idempotencyKey: string }) => {
            const { data } = await client.POST('/manage/ai/stop', {
                params: { header: { 'Idempotency-Key': idempotencyKey } },
                body: reason === undefined || reason === '' ? {} : { reason },
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: aiKeys.root });
        },
    });
}

export function useClearAiStop() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ idempotencyKey }: { idempotencyKey: string }) => {
            const { data } = await client.DELETE('/manage/ai/stop', {
                params: { header: { 'Idempotency-Key': idempotencyKey } },
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: aiKeys.root });
        },
    });
}

export function useAiPolicies() {
    const client = useApiClient();
    return useQuery({
        queryKey: aiKeys.policies(),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/ai/policies', { signal });
            return must(data);
        },
    });
}

export function useCreateAiPolicy() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ body, idempotencyKey }: { body: AiPolicyRequest; idempotencyKey: string }) => {
            const { data } = await client.POST('/manage/ai/policies', {
                params: { header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: aiKeys.root });
        },
    });
}

export function useActivateAiPolicy() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ version, idempotencyKey }: { version: number; idempotencyKey: string }) => {
            const { data } = await client.PATCH('/manage/ai/policies/{version}', {
                params: { path: { version }, header: { 'Idempotency-Key': idempotencyKey } },
                body: { state: 'active' },
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: aiKeys.root });
        },
    });
}

export function useAiRoutes() {
    const client = useApiClient();
    return useQuery({
        queryKey: aiKeys.routes(),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/ai/routes', { signal });
            return must(data);
        },
    });
}

export function useAiRuns(params: AiRunListParams) {
    const client = useApiClient();
    return useQuery({
        queryKey: aiKeys.runs(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/ai/runs', { params: { query: params }, signal });
            return must(data);
        },
    });
}

export function useAiRun(id: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: aiKeys.run(id),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/ai/runs/{id}', {
                params: { path: { id } },
                signal,
            });
            return must(data);
        },
    });
}

export function useAiDrafts(params: AiDraftListParams) {
    const client = useApiClient();
    return useQuery({
        queryKey: aiKeys.drafts(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/ai/drafts', { params: { query: params }, signal });
            return must(data);
        },
    });
}

export function useAcceptAiDraft() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            id,
            acknowledgeBlocking,
            idempotencyKey,
        }: {
            id: number;
            acknowledgeBlocking: boolean;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/ai/drafts/{id}/accept', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                body: { acknowledge_blocking: acknowledgeBlocking },
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: aiKeys.root });
        },
    });
}

export function useRejectAiDraft() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            id,
            reasonCode,
            idempotencyKey,
        }: {
            id: number;
            reasonCode: string;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/ai/drafts/{id}/reject', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                body: { reason_code: reasonCode },
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: aiKeys.root });
        },
    });
}

export function useAiUsage() {
    const client = useApiClient();
    return useQuery({
        queryKey: aiKeys.usage(),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/ai/usage', { signal });
            return must(data);
        },
    });
}
