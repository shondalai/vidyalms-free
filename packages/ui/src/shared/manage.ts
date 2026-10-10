import { translate as uiText } from '@/shared/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { CreateCourseRequest } from '@vidyalms/api-sdk';
import { useApiClient } from './context';

/*
 * The management calls both apps make.
 *
 * The admin app and the workbench each had their own copy of four hooks over
 * the same four endpoints, with their own parameter types and their own sort
 * unions, and the copies had already drifted where it mattered: the
 * workbench sent an Idempotency-Key it kept across retries and the admin
 * dialog sent none, so the SDK minted a fresh one per attempt and a retried
 * "New course" made a second course. One endpoint, one hook.
 *
 * App-specific keys stay in the apps. What is here is what both of them mean
 * by the same words.
 */

/** The client rejects with ProblemError on failure, so data present is the invariant. */
export function must<T>(value: T | undefined): T {
    if (value === undefined) {
        throw new Error(uiText("vidyalms.ui.79715b15d77cc409", "The server answered OK with an empty body where content was expected."));
    }
    return value;
}

/** Headers for one replay-safe logical operation; reuse the key across retries. */
export function idempotency(key: string | undefined): Record<string, string> | undefined {
    return key === undefined ? undefined : { 'Idempotency-Key': key };
}

/**
 * A new filter, sort or page is a new query key. Keeping the previous page on
 * screen while the next one loads means a table never blanks out under the
 * reader between two states of the same list.
 */
export function keepPrevious<T>(previous: T | undefined): T | undefined {
    return previous;
}

export type ManagedCourseSort =
    | 'title'
    | 'state'
    | 'enrolled_count'
    | 'active_count'
    | 'completion_rate'
    | 'published_at'
    | 'updated_at';

export interface CourseListParams {
    category_id?: number;
    state?: 'draft' | 'published' | 'archived' | 'trashed';
    q?: string;
    sort?: ManagedCourseSort;
    order?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

export interface CourseCategoryListParams {
    q?: string;
    sort?: 'name' | 'id';
    order?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

/**
 * The keys of everything below. Both apps invalidate through these, and both
 * nest under the shared ['vidyalms'] root so a broad invalidation still
 * reaches them.
 */
export const manageKeys = {
    root: ['vidyalms', 'manage'] as const,
    courses: (params: CourseListParams) => ['vidyalms', 'manage', 'courses', params] as const,
    managedCourse: (courseId: number) => ['vidyalms', 'manage', 'course', courseId] as const,
    courseCategories: (params: CourseCategoryListParams) =>
        ['vidyalms', 'manage', 'course-categories', params] as const,
    courseCategory: (id: number) => ['vidyalms', 'manage', 'course-categories', id] as const,
    userSearch: (q: string, limit: number) => ['vidyalms', 'manage', 'users', q, limit] as const,
};

export function useManagedCourses(params: CourseListParams, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: manageKeys.courses(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses', { params: { query: params }, signal });
            return must(data);
        },
    });
}

/** Resolve a stored course owner independently of a paged picker result. */
export function useManagedCourse(courseId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: courseId !== null,
        queryKey: manageKeys.managedCourse(courseId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}', {
                params: { path: { id: courseId as number } },
                signal,
            });
            return must(data);
        },
    });
}

/**
 * Native course categories, normalized by the host adapter. Each query asks
 * for one bounded page; the picker changes the page explicitly instead of
 * downloading a large Joomla tree or WordPress taxonomy.
 *
 * Previous data preserves the paging controls while the next page/search is
 * in flight. Interactive consumers must honor isFetching/isPlaceholderData so
 * a row from the previous query can never be selected as a current result.
 */
export function useCourseCategories(
    params: CourseCategoryListParams,
    options: { enabled?: boolean } = {},
) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: manageKeys.courseCategories(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/course-categories', {
                params: { query: params },
                signal,
            });
            return must(data);
        },
    });
}

/** Resolve the stored id independently of the current search page. */
export function useCourseCategory(id: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: id !== null,
        queryKey: manageKeys.courseCategory(id ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/course-categories/{id}', {
                params: { path: { id: id as number } },
                signal,
            });
            return must(data);
        },
    });
}

/**
 * Creating a course is not replay-safe on its own: the same request sent
 * twice makes two courses. The caller passes the key it will keep across
 * retries, which is what the save tracker exists to hold.
 */
export function useCreateCourse() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ body, idempotencyKey }: { body: CreateCourseRequest; idempotencyKey?: string }) => {
            const { data } = await client.POST('/manage/courses', { body, headers: idempotency(idempotencyKey) });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
}

/**
 * A new expiry, which the design promises keeps every bit of progress: it
 * resumes the suspended enrolment. Revocation is a decision, so an
 * entitlement that was revoked answers 409 and the caller grants a new one
 * instead.
 *
 * courseId is accepted and unused: the workbench knows which course it is
 * looking at and the admin app does not, and both invalidate the same thing.
 */
export function useExtendEntitlement() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            entitlementId,
            expiresAt,
            idempotencyKey,
        }: {
            courseId?: number;
            entitlementId: number;
            expiresAt: string | null;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/entitlements/{id}/extend', {
                params: { path: { id: entitlementId } },
                body: { expires_at: expiresAt },
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
 * The person picker behind the grant and enrol dialogs. The contract answers
 * 422 under two characters, so the query stays idle until then.
 */
export function useUserSearch(query: string, limit = 10) {
    const client = useApiClient();
    const trimmed = query.trim();
    return useQuery({
        enabled: trimmed.length >= 2,
        queryKey: manageKeys.userSearch(trimmed, limit),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/admin/users', {
                params: { query: { q: trimmed, limit } },
                signal,
            });
            return must(data).items;
        },
    });
}
