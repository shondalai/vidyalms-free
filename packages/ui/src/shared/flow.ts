import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { components } from '@vidyalms/api-sdk';
import { useApiClient } from './context';
import { idempotency, keepPrevious, must } from './manage';

/*
 * The learning-flow management calls: availability, cohorts, notifications
 * and registry-driven provider settings.
 *
 * They live beside shared/manage.ts and for its reason. The workbench
 * authors a restriction on a lesson and runs a course's cohorts; the admin
 * app configures providers, reads suppressions and answers a consent
 * question. Both are /manage routes, both apps mount them, and a second copy
 * of one hook is how the two surfaces start disagreeing about what a write
 * invalidates.
 *
 * Two contract facts are honoured here rather than in each screen. A read
 * route for a capability nothing registers answers 200 with an `available`
 * flag and an empty list, so a missing package is a normal answer and never
 * an error state; only a WRITE with nowhere to go answers 501. And every
 * mutation on this surface takes an Idempotency-Key, which the SDK mints per
 * request, so a caller retrying one logical write passes its own key.
 */

type Schemas = components['schemas'];

export type ProviderScopeType = 'site' | 'course' | 'cohort' | 'rule';

export type AvailabilitySubjectType = 'course' | 'module' | 'lesson' | 'quiz' | 'assignment';

/**
 * The stored discriminator is singular and the URL segment is plural, which
 * is the ordinary REST split and not an inconsistency: `subject_type` on a
 * row says what one row is, and the path names a collection. The map exists
 * so no caller writes either form from memory.
 */
const SUBJECT_SEGMENT = {
    course: 'courses',
    module: 'modules',
    lesson: 'lessons',
    quiz: 'quizzes',
    assignment: 'assignments',
} as const satisfies Record<AvailabilitySubjectType, string>;

export interface CohortListParams {
    state?: 'draft' | 'open' | 'running' | 'closed' | 'archived';
    page?: number;
    per_page?: number;
}

export interface CohortMemberParams {
    state?: 'invited' | 'waitlisted' | 'active' | 'completed' | 'withdrawn' | 'moved';
    q?: string;
    page?: number;
    per_page?: number;
}

export interface NotificationRuleParams {
    course_id?: number;
    trigger_key?: string;
    state?: 'enabled' | 'disabled';
    page?: number;
    per_page?: number;
}

export interface NotificationQueueParams {
    state?:
        | 'pending_fanout'
        | 'pending'
        | 'sending'
        | 'sent'
        | 'deferred'
        | 'suppressed'
        | 'failed_permanent'
        | 'cancelled';
    rule_id?: number;
    recipient_user_id?: number;
    channel_key?: string;
    page?: number;
    per_page?: number;
}

export interface SuppressionParams {
    channel_key?: string;
    reason?: 'hard_bounce' | 'complaint' | 'manual';
    /** Hashed on arrival; the plaintext never leaves the request. */
    address?: string;
    page?: number;
    per_page?: number;
}

/**
 * The keys of this surface. Availability nests under the subject it gates
 * rather than under the course, because a rule save has to invalidate the
 * one subject and the course outline and nothing else; cohorts and
 * notifications carry their own branches for the same reason.
 */
export const flowKeys = {
    root: ['vidyalms', 'flow'] as const,
    conditions: (surface: string) => ['vidyalms', 'flow', 'conditions', surface] as const,
    rule: (subjectType: string, subjectId: number, surface: string) =>
        ['vidyalms', 'flow', 'rule', subjectType, subjectId, surface] as const,
    grants: (courseId: number, userId: number | undefined) =>
        ['vidyalms', 'flow', 'grants', courseId, userId ?? 0] as const,
    cohorts: (courseId: number, params: CohortListParams) =>
        ['vidyalms', 'flow', 'cohorts', courseId, params] as const,
    cohort: (cohortId: number) => ['vidyalms', 'flow', 'cohort', cohortId] as const,
    cohortSchedule: (cohortId: number) => ['vidyalms', 'flow', 'cohort', cohortId, 'schedule'] as const,
    cohortMembers: (cohortId: number, params: CohortMemberParams) =>
        ['vidyalms', 'flow', 'cohort', cohortId, 'members', params] as const,
    dateOverrides: (cohortId: number) => ['vidyalms', 'flow', 'cohort', cohortId, 'date-overrides'] as const,
    notificationCatalogue: ['vidyalms', 'flow', 'notifications', 'catalogue'] as const,
    notificationRules: (params: NotificationRuleParams) =>
        ['vidyalms', 'flow', 'notifications', 'rules', params] as const,
    notificationQueue: (params: NotificationQueueParams) =>
        ['vidyalms', 'flow', 'notifications', 'queue', params] as const,
    notificationPolicies: ['vidyalms', 'flow', 'notifications', 'policies'] as const,
    suppressions: (params: SuppressionParams) => ['vidyalms', 'flow', 'notifications', 'suppressions', params] as const,
    consents: (userId: number, categoryKey: string | undefined) =>
        ['vidyalms', 'flow', 'notifications', 'consents', userId, categoryKey ?? ''] as const,
    providers: (registryKey: string, scopeType: ProviderScopeType, scopeId: number) =>
        ['vidyalms', 'flow', 'providers', registryKey, scopeType, scopeId] as const,
};

/* ── Availability ──────────────────────────────────────────────────── */

/**
 * The condition palette this site can offer, filtered to the surface being
 * authored. `applicability` is the guard on one grammar serving four
 * surfaces, so the picker asks for the surface rather than filtering client
 * side and hoping the save agrees.
 */
export function useAvailabilityCatalogue(
    surface: 'content_gating' | 'notification_rule' | 'award_criteria' | 'enrolment_gating',
    options: { enabled?: boolean } = {},
) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: flowKeys.conditions(surface),
        /* The registry does not change between two renders of one form. */
        staleTime: 5 * 60 * 1000,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/availability/conditions', {
                params: { query: { surface } },
                signal,
            });
            return must(data);
        },
    });
}

export function useAvailabilityRule(
    subjectType: 'course' | 'module' | 'lesson' | 'quiz' | 'assignment',
    subjectId: number | null,
    surface: 'content_gating' | 'enrolment_gating' = 'content_gating',
) {
    const client = useApiClient();
    return useQuery({
        enabled: subjectId !== null,
        queryKey: flowKeys.rule(subjectType, subjectId ?? 0, surface),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/{subject_type}/{subject_id}/availability', {
                params: {
                    path: { subject_type: SUBJECT_SEGMENT[subjectType], subject_id: subjectId ?? 0 },
                    query: { surface },
                },
                signal,
            });
            return must(data);
        },
    });
}

/**
 * Writes the whole tree. A null tree clears the restriction, exactly as
 * DELETE does, so a form that removes its last row does not need a second
 * verb: the author did one thing and it travels as one write.
 */
export function useSetAvailabilityRule() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            subjectType,
            subjectId,
            surface = 'content_gating',
            body,
            idempotencyKey,
        }: {
            subjectType: 'course' | 'module' | 'lesson' | 'quiz' | 'assignment';
            subjectId: number;
            surface?: 'content_gating' | 'enrolment_gating';
            body: Schemas['SetAvailabilityRuleRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/{subject_type}/{subject_id}/availability', {
                params: {
                    path: { subject_type: SUBJECT_SEGMENT[subjectType], subject_id: subjectId },
                    query: { surface },
                },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            /* A stored restriction changes what every learner-facing read
               answers, and the outline is one of them. */
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

/**
 * Evaluates a stored or draft tree as one named learner at one instant,
 * leaf by leaf.
 *
 * This is the only surface where `never` and `exhausted` are visible: to a
 * learner an item that will never open and an item that has closed both read
 * as blocked with a reason, and publishing the difference would tell them
 * which exclusions were administrative. An author has to see it, because it
 * is what decides whether the item counts towards completion.
 */
export function usePreviewAvailability() {
    const client = useApiClient();
    return useMutation({
        mutationFn: async ({
            body,
            idempotencyKey,
        }: {
            body: Schemas['AvailabilityPreviewRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/availability/preview', {
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
    });
}

export function useAvailabilityGrants(courseId: number, userId?: number, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: flowKeys.grants(courseId, userId),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/availability/grants', {
                params: { path: { id: courseId }, query: userId === undefined ? {} : { user_id: userId } },
                signal,
            });
            return must(data);
        },
    });
}

export function useCreateAvailabilityGrant() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            body: Schemas['AvailabilityGrantRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/courses/{id}/availability/grants', {
                params: { path: { id: courseId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useRevokeAvailabilityGrant() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ grantId, idempotencyKey }: { grantId: number; idempotencyKey?: string }) => {
            await client.DELETE('/manage/availability/grants/{id}', {
                params: { path: { id: grantId } },
                headers: idempotency(idempotencyKey),
            });
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

/* ── Cohorts ───────────────────────────────────────────────────────── */

export function useCohorts(courseId: number, params: CohortListParams = {}, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: flowKeys.cohorts(courseId, params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/cohorts', {
                params: { path: { id: courseId }, query: params },
                signal,
            });
            return must(data);
        },
    });
}

export function useCohort(cohortId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: cohortId !== null,
        queryKey: flowKeys.cohort(cohortId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/cohorts/{id}', {
                params: { path: { id: cohortId ?? 0 } },
                signal,
            });
            return must(data);
        },
    });
}

export function useCreateCohort() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            body: Schemas['CreateCohortRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/courses/{id}/cohorts', {
                params: { path: { id: courseId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useUpdateCohort() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            cohortId,
            body,
            idempotencyKey,
        }: {
            cohortId: number;
            body: Schemas['UpdateCohortRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/cohorts/{id}', {
                params: { path: { id: cohortId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

/**
 * Archives a run. Membership rows are history and are never deleted, which
 * is why the result reports how many were kept rather than how many were
 * removed.
 */
export function useArchiveCohort() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ cohortId, idempotencyKey }: { cohortId: number; idempotencyKey?: string }) => {
            const { data } = await client.DELETE('/manage/cohorts/{id}', {
                params: { path: { id: cohortId } },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useCohortSchedule(cohortId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: cohortId !== null,
        queryKey: flowKeys.cohortSchedule(cohortId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/cohorts/{id}/schedule', {
                params: { path: { id: cohortId ?? 0 } },
                signal,
            });
            return must(data);
        },
    });
}

export function useSetCohortSchedule() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            cohortId,
            entries,
            idempotencyKey,
        }: {
            cohortId: number;
            entries: Schemas['CohortScheduleEntry'][];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/cohorts/{id}/schedule', {
                params: { path: { id: cohortId } },
                body: { entries },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useCohortMembers(
    cohortId: number | null,
    params: CohortMemberParams = {},
    options: { enabled?: boolean } = {},
) {
    const client = useApiClient();
    return useQuery({
        enabled: cohortId !== null && (options.enabled ?? true),
        queryKey: flowKeys.cohortMembers(cohortId ?? 0, params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/cohorts/{id}/members', {
                params: { path: { id: cohortId ?? 0 }, query: params },
                signal,
            });
            return must(data);
        },
    });
}

export function useAddCohortMembers() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            cohortId,
            body,
            idempotencyKey,
        }: {
            cohortId: number;
            body: Schemas['AddCohortMembersRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/cohorts/{id}/members', {
                params: { path: { id: cohortId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useUpdateCohortMember() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            cohortId,
            userId,
            body,
            idempotencyKey,
        }: {
            cohortId: number;
            userId: number;
            body: Schemas['UpdateCohortMemberRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/cohorts/{id}/members/{user_id}', {
                params: { path: { id: cohortId, user_id: userId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

/**
 * Moves a learner between runs of the same course.
 *
 * Three writes and no fourth: the old membership closes, a new one opens,
 * the enrolment repoints. No progress row, attempt, submission, grade or
 * certificate is written or deleted, which is what distinguishes a move from
 * an unenrol and a re-enrol, and the result says so rather than leaving it
 * to be assumed.
 */
export function useMoveCohortMember() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            cohortId,
            userId,
            body,
            idempotencyKey,
        }: {
            cohortId: number;
            userId: number;
            body: Schemas['MoveCohortMemberRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/cohorts/{id}/members/{user_id}/move', {
                params: { path: { id: cohortId, user_id: userId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useDateOverrides(
    cohortId: number | null,
    params: { scope_type?: 'cohort' | 'group' | 'user'; target_type?: 'module' | 'lesson' | 'quiz' | 'assignment' } = {},
) {
    const client = useApiClient();
    return useQuery({
        enabled: cohortId !== null,
        queryKey: flowKeys.dateOverrides(cohortId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/cohorts/{id}/date-overrides', {
                params: { path: { id: cohortId ?? 0 }, query: params },
                signal,
            });
            return must(data);
        },
    });
}

export function useSetDateOverride() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            cohortId,
            body,
            idempotencyKey,
        }: {
            cohortId: number;
            body: Schemas['DateOverrideRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/cohorts/{id}/date-overrides', {
                params: { path: { id: cohortId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useDeleteDateOverride() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ overrideId, idempotencyKey }: { overrideId: number; idempotencyKey?: string }) => {
            await client.DELETE('/manage/date-overrides/{id}', {
                params: { path: { id: overrideId } },
                headers: idempotency(idempotencyKey),
            });
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

/* ── Notifications ─────────────────────────────────────────────────── */

/**
 * Triggers, audiences, channels, categories, schedule kinds and anchors, all
 * enumerated from registries rather than from constants. Nothing on the rule
 * form is typed from memory, which is why a package adding a trigger needs
 * no client change at all.
 */
export function useNotificationCatalogue(options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: flowKeys.notificationCatalogue,
        staleTime: 5 * 60 * 1000,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/notifications/catalogue', { signal });
            return must(data);
        },
    });
}

export function useNotificationRules(params: NotificationRuleParams = {}, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: flowKeys.notificationRules(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/notifications/rules', { params: { query: params }, signal });
            return must(data);
        },
    });
}

export function useCreateNotificationRule() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            body,
            idempotencyKey,
        }: {
            body: Schemas['NotificationRuleRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/notifications/rules', {
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useUpdateNotificationRule() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            ruleId,
            body,
            idempotencyKey,
        }: {
            ruleId: number;
            body: Schemas['NotificationRuleRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/notifications/rules/{id}', {
                params: { path: { id: ruleId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useDeleteNotificationRule() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ ruleId, idempotencyKey }: { ruleId: number; idempotencyKey?: string }) => {
            await client.DELETE('/manage/notifications/rules/{id}', {
                params: { path: { id: ruleId } },
                headers: idempotency(idempotencyKey),
            });
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useNotificationQueue(params: NotificationQueueParams = {}, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: flowKeys.notificationQueue(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/notifications/queue', { params: { query: params }, signal });
            return must(data);
        },
    });
}

/**
 * Cancels one pending row. A row already claimed for sending answers 409
 * rather than pretending, and the cancelled row keeps its dedupe digest so
 * the occurrence is never re-enqueued behind the operator's back.
 */
export function useCancelQueuedNotification() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ queueId, idempotencyKey }: { queueId: number; idempotencyKey?: string }) => {
            const { data } = await client.POST('/manage/notifications/queue/{id}/cancel', {
                params: { path: { id: queueId } },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useNotificationPolicies(options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: flowKeys.notificationPolicies,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/notifications/policies', { signal });
            return must(data);
        },
    });
}

/**
 * The site policy matrix.
 *
 * A `forced` state on an optional or marketing category is refused at save,
 * not merely discouraged in the form: an objection to direct marketing
 * admits no balancing, so the rule is validation and the form only mirrors
 * it. The cells arrive carrying the states each one may take.
 */
export function useSetNotificationPolicies() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            body,
            idempotencyKey,
        }: {
            body: Schemas['SetNotificationPolicyMatrixRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/notifications/policies', {
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

export function useSuppressions(params: SuppressionParams = {}, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: flowKeys.suppressions(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/notifications/suppressions', {
                params: { query: params },
                signal,
            });
            return must(data);
        },
    });
}

export function useSuppressAddress() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            body,
            idempotencyKey,
        }: {
            body: Schemas['SuppressAddressRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/notifications/suppressions', {
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

/**
 * The append-only consent record of one person.
 *
 * It takes a user id because it is an audit answer about one person and not
 * a list to browse: a withdrawal is a new row and never a deleted one, which
 * is exactly what makes "did they ever object" answerable.
 */
export function useNotificationConsents(
    userId: number | null,
    categoryKey?: string,
    options: { enabled?: boolean } = {},
) {
    const client = useApiClient();
    return useQuery({
        enabled: userId !== null && (options.enabled ?? true),
        queryKey: flowKeys.consents(userId ?? 0, categoryKey),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/notifications/consents', {
                params: {
                    query: {
                        user_id: userId ?? 0,
                        ...(categoryKey === undefined ? {} : { category_key: categoryKey }),
                    },
                },
                signal,
            });
            return must(data);
        },
    });
}

/* ── Provider settings, for any registry ───────────────────────────── */

export function useRegistryProviders(
    registryKey: string,
    scopeType: ProviderScopeType = 'site',
    scopeId = 0,
    options: { enabled?: boolean } = {},
) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: flowKeys.providers(registryKey, scopeType, scopeId),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/providers/{registry_key}', {
                params: { path: { registry_key: registryKey }, query: { scope_type: scopeType, scope_id: scopeId } },
                signal,
            });
            return must(data);
        },
    });
}

export function useSetProviderSettings() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            registryKey,
            providerKey,
            body,
            idempotencyKey,
        }: {
            registryKey: string;
            providerKey: string;
            body: Schemas['ProviderSettingsRequest'];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/providers/{registry_key}/{provider_key}/settings', {
                params: { path: { registry_key: registryKey, provider_key: providerKey } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}
