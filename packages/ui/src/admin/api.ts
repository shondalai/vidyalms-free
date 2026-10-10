import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { EnrolLearnerRequest } from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import {
    idempotency,
    keepPrevious,
    manageKeys,
    must,
    useCreateCourse,
    useCourseCategories,
    useCourseCategory,
    useExtendEntitlement,
    useManagedCourses,
    useUserSearch,

} from '@/shared/manage';

/*
 * One endpoint, one hook. The four calls both apps make live in
 * shared/manage.ts and are re-exported here so every screen keeps
 * importing from its own app.
 */
export {
    idempotency,
    keepPrevious,
    manageKeys,
    must,
    useCreateCourse,
    useCourseCategories,
    useCourseCategory,
    useExtendEntitlement,
    useManagedCourses,
    useUserSearch,
};
export type { CourseCategoryListParams, CourseListParams, ManagedCourseSort } from '@/shared/manage';

export { ProblemError } from '@vidyalms/api-sdk';
export type {
    AdminDashboard,
    AdminEnrolmentPage,
    AdminLearner,
    AdminLearnerPage,
    CertificatePage,
    CertificateRecord,
    CourseRef,
    CreateCourseRequest,
    EnrolLearnerRequest,
    EnrolmentRecord,
    Entitlement,
    Instructor,
    LearnerRecord,
    ManagedCourse,
    ManagedCourseDetail,
    ManagedCoursePage,
    PlatformSettings,
    UserAccount,
} from '@vidyalms/api-sdk';

/*
 * Every admin query and mutation, over the generated SDK and nothing else.
 * Keys extend the shared ['vidyalms'] root, so the learner apps' root
 * invalidation still reaches these and an admin mutation can invalidate its
 * own subtree without touching anything else.
 *
 * Lists take their whole parameter object into the key: the contract pages,
 * sorts and filters on the server (never a client-side sort), so a
 * different parameter set is a different resource.
 */

export const adminKeys = {
    root: ['vidyalms', 'admin'] as const,
    dashboard: (params: DashboardParams) => ['vidyalms', 'admin', 'dashboard', params] as const,
    courseCount: (state: CourseState) => ['vidyalms', 'admin', 'course-count', state] as const,
    enrolments: (params: EnrolmentListParams) => ['vidyalms', 'admin', 'enrolments', params] as const,
    learners: (params: LearnerListParams) => ['vidyalms', 'admin', 'learners', params] as const,
    learner: (userId: number) => ['vidyalms', 'admin', 'learner', userId] as const,
    certificates: (params: CertificateListParams) => ['vidyalms', 'admin', 'certificates', params] as const,
    settings: ['vidyalms', 'admin', 'settings'] as const,
};

export interface DashboardParams {
    period_days?: 7 | 30;
    waiting_days?: number;
    expiring_days?: number;
}

export type CourseState = 'draft' | 'published' | 'archived' | 'trashed';

export interface EnrolmentListParams {
    status?: 'active' | 'suspended' | 'completed' | 'cancelled';
    expiring_within_days?: number;
    course_id?: number;
    q?: string;
    sort?: 'expires_at' | 'enrolled_at' | 'last_active' | 'progress' | 'status';
    order?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

export interface LearnerListParams {
    q?: string;
    sort?: 'last_active' | 'enrolled_at' | 'progress' | 'courses';
    order?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

export interface CertificateListParams {
    /*
     * The six-value status enumeration, plus "valid" as the legacy filter
     * convenience: issued, not revoked, not suspended, not superseded and
     * inside its validity window. The enumeration values filter on the
     * status the published precedence resolves to.
     */
    status?: 'valid' | 'active' | 'not_yet_valid' | 'expired' | 'suspended' | 'revoked' | 'superseded';
    course_id?: number;
    /** Which template issued it: the population a bulk reissue would touch. */
    template_id?: number;
    q?: string;
    sort?: 'issued_at' | 'expires_at' | 'revoked_at';
    order?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

export function useAdminDashboard(params: DashboardParams = {}) {
    const client = useApiClient();
    return useQuery({
        queryKey: adminKeys.dashboard(params),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/admin/dashboard', { params: { query: params }, signal });
            return must(data);
        },
    });
}

/**
 * The count behind each state chip of the course list. ManagedCoursePage
 * carries no facets (unlike AdminEnrolmentPage), so the only honest source
 * of "Published (14)" is one minimal page per state. They are three tiny
 * cached reads, and they work for a caller who holds teach but not reports,
 * which a count from the dashboard would not.
 */
export function useCourseStateCount(state: CourseState, enabled = true) {
    const client = useApiClient();
    return useQuery({
        enabled,
        queryKey: adminKeys.courseCount(state),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses', {
                params: { query: { state, per_page: 1 } },
                signal,
            });
            return must(data).total;
        },
    });
}

export function useAdminEnrolments(params: EnrolmentListParams, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: adminKeys.enrolments(params),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/admin/enrolments', { params: { query: params }, signal });
            return must(data);
        },
    });
}

export function useAdminLearners(params: LearnerListParams, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: adminKeys.learners(params),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/admin/learners', { params: { query: params }, signal });
            return must(data);
        },
    });
}

export function useLearnerRecord(userId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: userId !== null,
        queryKey: adminKeys.learner(userId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/admin/learners/{user_id}', {
                params: { path: { user_id: userId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useAdminCertificates(params: CertificateListParams, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: adminKeys.certificates(params),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/admin/certificates', { params: { query: params }, signal });
            return must(data);
        },
    });
}

export function usePlatformSettings() {
    const client = useApiClient();
    return useQuery({
        queryKey: adminKeys.settings,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/admin/settings', { signal });
            return must(data);
        },
    });
}

/**
 * Grant access: a manual entitlement plus the enrolment it produces. Every
 * admin list can change (the learner's record, the enrolment page, the
 * dashboard counts), so the whole admin subtree is invalidated.
 */
export function useGrantAccess() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ courseId, body, idempotencyKey }: {
            courseId: number;
            body: EnrolLearnerRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/courses/{id}/learners', {
                params: { path: { id: courseId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: adminKeys.root });
        },
    });
}

export function useRevokeCertificate() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            certificateId,
            reason,
            note,
        }: {
            certificateId: number;
            reason: string;
            note?: string;
        }) => {
            // The reason is required by the contract rather than optional,
            // because revocation is permanent and "why" is the question every
            // later audit asks.
            const { data } = await client.POST('/admin/certificates/{id}/revoke', {
                params: { path: { id: certificateId } },
                body: { reason, note },
            });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: adminKeys.root });
        },
    });
}
