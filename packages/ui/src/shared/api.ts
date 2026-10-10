import { translate as uiText } from '@/shared/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ProblemError } from '@vidyalms/api-sdk';
import type { AttemptView, HeartbeatRequest, SaveAnswersRequest } from '@vidyalms/api-sdk';
import { useApiClient } from './context';

export { useApiClient } from './context';
export { ProblemError, isProblem } from '@vidyalms/api-sdk';
export type {
    ApiProblem,
    AttemptDetail,
    AttemptQuestion,
    AttemptStarted,
    AttemptView,
    Certificate,
    CourseDetail,
    Curriculum,
    CurriculumEnrolment,
    CurriculumLesson,
    CurriculumModule,
    HeartbeatRequest,
    LessonQuizLastAttempt,
    LessonQuizSummary,
    LessonView,
    LessonViewProgress,
    MyProgress,
    SaveAnswersRequest,
} from '@vidyalms/api-sdk';

/**
 * Every query key of both apps, in one place. Invalidate through these,
 * never through ad hoc arrays.
 */
export const queryKeys = {
    root: ['vidyalms'] as const,
    course: (slug: string) => ['vidyalms', 'course', slug] as const,
    curriculum: (courseId: number) => ['vidyalms', 'curriculum', courseId] as const,
    lesson: (lessonId: number) => ['vidyalms', 'lesson', lessonId] as const,
    myProgress: (courseId: number) => ['vidyalms', 'my-progress', courseId] as const,
    attempt: (attemptId: number) => ['vidyalms', 'attempt', attemptId] as const,
    myCertificates: ['vidyalms', 'my-certificates'] as const,
};

/** The client rejects with ProblemError on failure, so data present is the invariant. */
function must<T>(value: T | undefined): T {
    if (value === undefined) {
        throw new Error(uiText("vidyalms.ui.79715b15d77cc409", "The server answered OK with an empty body where content was expected."));
    }
    return value;
}

/**
 * What a 409 problem means, read from the problem detail (the contract's
 * conflicts share status 409 and type about:blank; the detail sentence is
 * the discriminator). Callers branch on this instead of treating every
 * conflict alike: a suspended enrolment, an already-submitted attempt, an
 * expired attempt deadline and an exhausted attempt limit each have their
 * own recovery.
 */
export type ConflictKind = 'suspended' | 'already_submitted' | 'time_expired' | 'attempt_limit' | 'other';

export function conflictKind(error: unknown): ConflictKind | null {
    if (!(error instanceof ProblemError) || error.status !== 409) {
        return null;
    }
    const detail = error.problem.detail ?? '';
    if (/enrolment is suspended/i.test(detail)) {
        return 'suspended';
    }
    if (/time expired/i.test(detail)) {
        return 'time_expired';
    }
    if (/already been submitted|while the attempt is in progress/i.test(detail)) {
        return 'already_submitted';
    }
    if (/attempt limit/i.test(detail)) {
        return 'attempt_limit';
    }
    return 'other';
}

/** Headers for one replay-safe logical operation; reuse the key across retries. */
function idempotency(key: string | undefined): Record<string, string> | undefined {
    return key === undefined ? undefined : { 'Idempotency-Key': key };
}

export function useCourse(slug: string) {
    const client = useApiClient();
    return useQuery({
        queryKey: queryKeys.course(slug),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/courses/{slug}', {
                params: { path: { slug } },
                signal,
            });
            return must(data);
        },
    });
}

export function useCurriculum(courseId: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: queryKeys.curriculum(courseId),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/courses/{id}/curriculum', {
                params: { path: { id: courseId } },
                signal,
            });
            return must(data);
        },
    });
}

/** Pass null while no lesson is selected; the query stays idle. */
export function useLesson(lessonId: number | null) {
    const client = useApiClient();
    return useQuery({
        queryKey: queryKeys.lesson(lessonId ?? 0),
        enabled: lessonId !== null,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/lessons/{id}', {
                params: { path: { id: lessonId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useMyProgress(courseId: number, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: queryKeys.myProgress(courseId),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/me/progress', {
                params: { query: { course_id: courseId } },
                signal,
            });
            return must(data);
        },
    });
}

/** The learner view of one attempt; the quiz runner's resume path. */
export function useAttempt(attemptId: number | null) {
    const client = useApiClient();
    return useQuery({
        queryKey: queryKeys.attempt(attemptId ?? 0),
        enabled: attemptId !== null,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/attempts/{id}', {
                params: { path: { id: attemptId as number } },
                signal,
            });
            return must(data);
        },
    });
}

/**
 * Start (or resume) an attempt. The server is idempotent while an attempt
 * is open; the SDK adds an Idempotency-Key on top.
 */
export function useStartAttempt() {
    const client = useApiClient();
    return useMutation({
        mutationFn: async ({ quizId }: { quizId: number }) => {
            const { data } = await client.POST('/quizzes/{id}/attempts', {
                params: { path: { id: quizId } },
            });
            return must(data);
        },
    });
}

/**
 * Autosave upsert of answers, keyed by question id. A 409 arrives as
 * ProblemError; the caller reads its kind (already submitted, or the timed
 * deadline passed) through conflictKind. Callers that retry pass the same
 * idempotencyKey until the save lands, so a retry replays instead of
 * executing twice.
 */
export function useSaveAnswers() {
    const client = useApiClient();
    return useMutation({
        mutationFn: async ({
            attemptId,
            answers,
            idempotencyKey,
        }: {
            attemptId: number;
            answers: SaveAnswersRequest['answers'];
            idempotencyKey?: string;
        }) => {
            await client.PUT('/attempts/{id}/answers', {
                params: { path: { id: attemptId } },
                body: { answers },
                headers: idempotency(idempotencyKey),
            });
        },
    });
}

/**
 * Submit closes the attempt and reveals exactly what a subsequent GET
 * would. Everything derived from progress is invalidated, because passing
 * a quiz can complete a lesson, the course, or both.
 */
export function useSubmitAttempt() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            attemptId,
            idempotencyKey,
        }: {
            attemptId: number;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/attempts/{id}/submit', {
                params: { path: { id: attemptId } },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (data: AttemptView, { attemptId }) => {
            queryClient.setQueryData(queryKeys.attempt(attemptId), data);
            void queryClient.invalidateQueries({ queryKey: queryKeys.root });
        },
    });
}

/**
 * One heartbeat POST. This is only the mutation wrapper; the 15s heartbeat
 * ENGINE (interval, retry, offline escalation) lives in the player app.
 * No invalidation here: a heartbeat every 15 seconds must not refetch the
 * world.
 */
export function useHeartbeat() {
    const client = useApiClient();
    return useMutation({
        mutationFn: async ({
            lessonId,
            body,
            idempotencyKey,
        }: {
            lessonId: number;
            body: HeartbeatRequest;
            idempotencyKey?: string;
        }) => {
            await client.POST('/lessons/{id}/progress', {
                params: { path: { id: lessonId } },
                body,
                headers: idempotency(idempotencyKey),
            });
        },
    });
}

/**
 * The caller's certificates, newest first. The player fetches this lazily
 * for the course-complete state; pass enabled false until that state shows.
 */
export function useMyCertificates(enabled = true, query: { page?: number; per_page?: number; course_id?: number } = {}) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...queryKeys.myCertificates, query],
        enabled,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/me/certificates', { params: { query }, signal });
            return must(data);
        },
    });
}
