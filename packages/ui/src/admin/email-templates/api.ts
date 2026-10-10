import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { components } from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { keepPrevious, must } from '@/shared/manage';

type Schemas = components['schemas'];

export type NotificationTemplate = Schemas['NotificationTemplate'];
export type NotificationTemplateDetail = Schemas['NotificationTemplateDetail'];
export type NotificationTemplateSource = Schemas['NotificationTemplateSource'];
export type NotificationTemplatePreview = Schemas['NotificationTemplatePreview'];
export type NotificationTemplateTestDelivery = Schemas['NotificationTemplateTestDelivery'];
export type NotificationTemplateStatus = 'published' | 'draft_changes' | 'draft_only';

export interface NotificationTemplateListParams {
    template_key?: string;
    locale?: string;
    channel_key?: string;
    search?: string;
    trigger_key?: string;
    status?: NotificationTemplateStatus;
    page?: number;
    per_page?: number;
}

export interface NotificationTemplateIdentity {
    templateKey: string;
    locale: string;
    channelKey: string;
}

export const notificationTemplateKeys = {
    root: ['vidyalms', 'admin', 'email-templates'] as const,
    lists: () => ['vidyalms', 'admin', 'email-templates', 'list'] as const,
    list: (params: NotificationTemplateListParams) =>
        ['vidyalms', 'admin', 'email-templates', 'list', params] as const,
    detail: (identity: NotificationTemplateIdentity) =>
        ['vidyalms', 'admin', 'email-templates', 'detail', identity] as const,
};

function path(identity: NotificationTemplateIdentity) {
    return {
        template_key: identity.templateKey,
        locale: identity.locale,
        channel_key: identity.channelKey,
    };
}

/** A fresh logical operation key. Callers keep it when they explicitly retry the same action. */
export function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `ui-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}

export function useNotificationTemplatePage(
    params: NotificationTemplateListParams,
    options: { enabled?: boolean } = {},
) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: notificationTemplateKeys.list(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/notifications/templates', {
                params: { query: params },
                signal,
            });
            return must(data);
        },
    });
}

export function useNotificationTemplateCount(
    status?: NotificationTemplateStatus,
    scope: Pick<NotificationTemplateListParams, 'locale' | 'channel_key'> = {},
) {
    const client = useApiClient();
    const params: NotificationTemplateListParams = { ...scope, status, page: 1, per_page: 1 };
    return useQuery({
        queryKey: notificationTemplateKeys.list(params),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/notifications/templates', {
                params: { query: params },
                signal,
            });
            return must(data).total;
        },
    });
}

export function useNotificationTemplateDetail(identity: NotificationTemplateIdentity | null) {
    const client = useApiClient();
    return useQuery({
        enabled: identity !== null,
        queryKey: notificationTemplateKeys.detail(
            identity ?? { templateKey: '', locale: '', channelKey: '' },
        ),
        queryFn: async ({ signal }) => {
            const exact = identity as NotificationTemplateIdentity;
            const { data } = await client.GET(
                '/manage/notifications/templates/{template_key}/{locale}/{channel_key}',
                { params: { path: path(exact) }, signal },
            );
            return must(data);
        },
    });
}

function useTemplateMutationInvalidation() {
    const queryClient = useQueryClient();
    return (identity: NotificationTemplateIdentity, detail: NotificationTemplateDetail) => {
        queryClient.setQueryData(notificationTemplateKeys.detail(identity), detail);
        void queryClient.invalidateQueries({ queryKey: notificationTemplateKeys.lists() });
    };
}

export function useSaveNotificationTemplateDraft() {
    const client = useApiClient();
    const update = useTemplateMutationInvalidation();
    return useMutation({
        mutationFn: async ({
            identity,
            source,
            expectedRevision,
            idempotencyKey,
        }: {
            identity: NotificationTemplateIdentity;
            source: NotificationTemplateSource;
            expectedRevision: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.PUT(
                '/manage/notifications/templates/{template_key}/{locale}/{channel_key}/draft',
                {
                    params: { path: path(identity) },
                    headers: { 'Idempotency-Key': idempotencyKey },
                    body: { source, expected_revision: expectedRevision },
                },
            );
            return must(data);
        },
        onSuccess: (data, variables) => update(variables.identity, data),
    });
}

export function useDiscardNotificationTemplateDraft() {
    const client = useApiClient();
    const update = useTemplateMutationInvalidation();
    return useMutation({
        mutationFn: async ({
            identity,
            expectedRevision,
            idempotencyKey,
        }: {
            identity: NotificationTemplateIdentity;
            expectedRevision: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.DELETE(
                '/manage/notifications/templates/{template_key}/{locale}/{channel_key}/draft',
                {
                    params: {
                        path: path(identity),
                        query: { expected_revision: expectedRevision },
                    },
                    headers: { 'Idempotency-Key': idempotencyKey },
                },
            );
            return must(data);
        },
        onSuccess: (data, variables) => update(variables.identity, data),
    });
}

export function usePublishNotificationTemplateDraft() {
    const client = useApiClient();
    const update = useTemplateMutationInvalidation();
    return useMutation({
        mutationFn: async ({
            identity,
            expectedRevision,
            idempotencyKey,
        }: {
            identity: NotificationTemplateIdentity;
            expectedRevision: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST(
                '/manage/notifications/templates/{template_key}/{locale}/{channel_key}/publish',
                {
                    params: { path: path(identity) },
                    headers: { 'Idempotency-Key': idempotencyKey },
                    body: { expected_revision: expectedRevision },
                },
            );
            return must(data);
        },
        onSuccess: (data, variables) => update(variables.identity, data),
    });
}

export function usePreviewNotificationTemplate() {
    const client = useApiClient();
    return useMutation({
        mutationFn: async ({
            identity,
            source,
        }: {
            identity: NotificationTemplateIdentity;
            source: NotificationTemplateSource;
        }) => {
            const { data } = await client.POST(
                '/manage/notifications/templates/{template_key}/{locale}/{channel_key}/preview',
                { params: { path: path(identity) }, body: { source } },
            );
            return must(data);
        },
    });
}

export function useTestSendNotificationTemplate() {
    const client = useApiClient();
    return useMutation({
        mutationFn: async ({
            identity,
            source,
            idempotencyKey,
        }: {
            identity: NotificationTemplateIdentity;
            source: NotificationTemplateSource;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST(
                '/manage/notifications/templates/{template_key}/{locale}/{channel_key}/test-send',
                {
                    params: {
                        path: path(identity),
                        header: { 'Idempotency-Key': idempotencyKey },
                    },
                    body: { source },
                },
            );
            return must(data);
        },
    });
}
