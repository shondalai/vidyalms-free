import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { useApiClient } from '@/shared/context';
import {
    idempotency,
    keepPrevious,
    must,
    useCreateCourse,
    useCourseCategories,
    useCourseCategory,
    useExtendEntitlement,
    useManagedCourse,
    useManagedCourses,
    useUserSearch,

} from '@/shared/manage';

/*
 * One endpoint, one hook: the four calls this app shares with the admin
 * app live in shared/manage.ts and are re-exported here.
 */
export {
    useCourseCategories,
    useCourseCategory,
    useCreateCourse,
    useExtendEntitlement,
    useManagedCourse,
    useManagedCourses,
    useUserSearch,
};
export type { CourseCategoryListParams, CourseListParams, ManagedCourseSort } from '@/shared/manage';
import { RESTORED_STATE, type ArchiveOutcome } from './lib/archive';
import type {
    AddLessonRequest,
    AddModuleRequest,
    AddQuestionRequest,
    AssignmentConfigRequest,
    EnrolLearnerRequest,
    GradeAnswerRequest,
    GradeSubmissionRequest,
    QuizSettingsRequest,
    UpdateCourseRequest,
    UpdateLessonRequest,
    UpdateModuleRequest,
    UpdateQuestionRequest,
} from '@vidyalms/api-sdk';

/*
 * Every call of the workbench, in one place, over the generated SDK. Same
 * pattern as src/shared/api.ts: queries carry their key from queryKeys,
 * mutations invalidate exactly what their write can have changed, and the
 * client rejects with ProblemError so one failure path serves every screen.
 *
 * The SDK puts an Idempotency-Key on every POST, PUT, PATCH and DELETE, so
 * nothing here has to; a caller that retries the same logical write passes
 * its own key to replay instead of executing twice.
 */

export type { ConflictKind } from '@/shared/api';
export { conflictKind, isProblem, ProblemError, useApiClient } from '@/shared/api';

export interface GradebookParams {
    status?: 'pending' | 'graded';
    type?: 'submission' | 'quiz_answer';
    sort?: 'waiting_since' | 'graded_at' | 'learner';
    order?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

export interface LearnerParams {
    status?: 'active' | 'suspended' | 'completed' | 'cancelled';
    q?: string;
    sort?: 'name' | 'progress' | 'last_active' | 'status' | 'enrolled_at';
    order?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

/**
 * Every query key of the workbench. Invalidate through these, never through
 * ad hoc arrays. The course branch nests everything scoped to one course so
 * a single write can invalidate the whole workbench when it has to.
 */
export const queryKeys = {
    root: ['vidyalms', 'manage'] as const,
    lessonTypes: ['vidyalms', 'lesson-types'] as const,
    questionTypes: ['vidyalms', 'question-types'] as const,
    course: (courseId: number) => ['vidyalms', 'manage', 'course', courseId] as const,
    curriculum: (courseId: number) => ['vidyalms', 'manage', 'course', courseId, 'curriculum'] as const,
    quizzes: (courseId: number) => ['vidyalms', 'manage', 'course', courseId, 'quizzes'] as const,
    quiz: (quizId: number) => ['vidyalms', 'manage', 'quiz', quizId] as const,
    gradebook: (courseId: number, params: GradebookParams) =>
        ['vidyalms', 'manage', 'course', courseId, 'gradebook', params] as const,
    gradeItems: (courseId: number) =>
        ['vidyalms', 'manage', 'course', courseId, 'gradebook', 'items'] as const,
    learners: (courseId: number, params: LearnerParams) =>
        ['vidyalms', 'manage', 'course', courseId, 'learners', params] as const,
    instructors: (courseId: number) => ['vidyalms', 'manage', 'course', courseId, 'instructors'] as const,
};

/**
 * Everything the workbench shows about one course, refetched after a write
 * that can move a count in the header (the lesson total, the pending badge)
 * or a row in another tab.
 */
function invalidateCourse(queryClient: QueryClient, courseId: number): void {
    void queryClient.invalidateQueries({ queryKey: queryKeys.course(courseId) });
}

/**
 * The same refetch, on the failure of a write that was shown optimistically.
 * A refused reorder leaves the screen holding an order the server never
 * accepted, so the list has to come back from the server rather than sit
 * there being wrong; the tracker keeps the failed write's idempotency key,
 * so the retry still replays the same logical write.
 *
 * @returns the two handlers an order mutation needs
 */
function refetchCourseEitherWay(
    queryClient: QueryClient,
): {
    onSuccess: (data: unknown, variables: { courseId: number }) => void;
    onError: (error: unknown, variables: { courseId: number }) => void;
} {
    const refetch = (_result: unknown, { courseId }: { courseId: number }) => invalidateCourse(queryClient, courseId);

    return { onSuccess: refetch, onError: refetch };
}

/* ── Registries ─────────────────────────────────────────────────────── */

/**
 * The lesson types this site can author. Registry-driven: a free site
 * answers five and a site with an add-on answers more from the same route,
 * so the add-lesson menu is never a hardcoded list.
 */
export function useLessonTypes() {
    const client = useApiClient();
    return useQuery({
        queryKey: queryKeys.lessonTypes,
        staleTime: 5 * 60_000,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/lesson-types', { signal });
            return must(data).items;
        },
    });
}

/**
 * The question types this site can author and the presets registered over
 * them, which is exactly what the type picker lists. A free site answers six
 * types and their six aliases; Pro types and Pro presets simply do not
 * appear because they are not registered.
 *
 * Both halves come from one request because they are one answer: a preset
 * names a type, and a picker that had them from two queries could render a
 * preset whose type had not arrived yet.
 */
export function useQuestionTypes() {
    const client = useApiClient();
    return useQuery({
        queryKey: queryKeys.questionTypes,
        staleTime: 5 * 60_000,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/question-types', { signal });
            const answer = must(data);
            return { types: answer.items, presets: answer.presets ?? [] };
        },
    });
}

/* ── Courses ────────────────────────────────────────────────────────── */

export function useUpdateCourse() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            body: UpdateCourseRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/courses/{id}', {
                params: { path: { id: courseId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (data, { courseId }) => {
            queryClient.setQueryData(queryKeys.course(courseId), data);
            void queryClient.invalidateQueries({ queryKey: queryKeys.root });
        },
    });
}

/** Publish and archive are the two state moves; PATCH never changes state. */
export function useSetCourseState() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            action,
            idempotencyKey,
        }: {
            courseId: number;
            action: 'publish' | 'archive';
            idempotencyKey?: string;
        }) => {
            const path = action === 'publish' ? '/manage/courses/{id}/publish' : '/manage/courses/{id}/archive';
            const { data } = await client.POST(path, {
                params: { path: { id: courseId } },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (data, { courseId }) => {
            queryClient.setQueryData(queryKeys.course(courseId), data);
            void queryClient.invalidateQueries({ queryKey: queryKeys.root });
        },
    });
}

/** The whole list in one write: courses left out are dropped. */
export function useSetPrerequisites() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            courseIds,
            idempotencyKey,
        }: {
            courseId: number;
            courseIds: number[];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/courses/{id}/prerequisites', {
                params: { path: { id: courseId } },
                body: { course_ids: courseIds },
                headers: idempotency(idempotencyKey),
            });
            return must(data).items;
        },
        ...refetchCourseEitherWay(queryClient),
    });
}

export function useInstructors(courseId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: courseId !== null,
        queryKey: queryKeys.instructors(courseId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/instructors', {
                params: { path: { id: courseId as number } },
                signal,
            });
            return must(data).items;
        },
    });
}

export function useAssignInstructor() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            userId,
            role,
            idempotencyKey,
        }: {
            courseId: number;
            userId: number;
            role?: string;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/courses/{id}/instructors', {
                params: { path: { id: courseId } },
                body: role === undefined ? { user_id: userId } : { user_id: userId, role },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

export function useRemoveInstructor() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            userId,
            idempotencyKey,
        }: {
            courseId: number;
            userId: number;
            idempotencyKey?: string;
        }) => {
            await client.DELETE('/manage/courses/{id}/instructors/{user_id}', {
                params: { path: { id: courseId, user_id: userId } },
                headers: idempotency(idempotencyKey),
            });
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

/* ── Curriculum ─────────────────────────────────────────────────────── */

/** Draft modules and lessons included, with the stored content verbatim. */
export function useAuthoringCurriculum(courseId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: courseId !== null,
        queryKey: queryKeys.curriculum(courseId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/curriculum', {
                params: { path: { id: courseId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useAddModule() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            body: AddModuleRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/courses/{id}/modules', {
                params: { path: { id: courseId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

export function useUpdateModule() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            moduleId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            moduleId: number;
            body: UpdateModuleRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/modules/{id}', {
                params: { path: { id: moduleId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

/**
 * Explicit order, never implicit: the body lists every module of the course
 * exactly once and the server rewrites positions 1..n.
 */
export function useReorderModules() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            moduleIds,
            idempotencyKey,
        }: {
            courseId: number;
            moduleIds: number[];
            idempotencyKey?: string;
        }) => {
            await client.PUT('/manage/courses/{id}/modules/order', {
                params: { path: { id: courseId } },
                body: { module_ids: moduleIds },
                headers: idempotency(idempotencyKey),
            });
        },
        ...refetchCourseEitherWay(queryClient),
    });
}

export function useAddLesson() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            moduleId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            moduleId: number;
            body: AddLessonRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/modules/{id}/lessons', {
                params: { path: { id: moduleId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

export function useUpdateLesson() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            lessonId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            lessonId: number;
            body: UpdateLessonRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/lessons/{id}', {
                params: { path: { id: lessonId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

export function useReorderLessons() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            moduleId,
            lessonIds,
            idempotencyKey,
        }: {
            courseId: number;
            moduleId: number;
            lessonIds: number[];
            idempotencyKey?: string;
        }) => {
            await client.PUT('/manage/modules/{id}/lessons/order', {
                params: { path: { id: moduleId } },
                body: { lesson_ids: lessonIds },
                headers: idempotency(idempotencyKey),
            });
        },
        ...refetchCourseEitherWay(queryClient),
    });
}

/** The lesson lands last in the target module; reorder it there afterwards. */
export function useMoveLesson() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            lessonId,
            moduleId,
            idempotencyKey,
        }: {
            courseId: number;
            lessonId: number;
            moduleId: number;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/lessons/{id}/move', {
                params: { path: { id: lessonId } },
                body: { module_id: moduleId },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

/** Full replacement: an omitted optional field takes its documented default. */
export function useConfigureAssignment() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            lessonId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            lessonId: number;
            body: AssignmentConfigRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/lessons/{id}/assignment', {
                params: { path: { id: lessonId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

/* ── Archive and restore ────────────────────────────────────────────── */

/*
 * DELETE on a manage route archives: the row moves to state "archived" and
 * a PATCH of its state brings it back, which is why nothing here is called
 * a delete. Both routes are in the generated schema, so both calls are
 * typed, and the outcome the server sends (lessons_archived,
 * enrolments_affected, progress_recompute) travels to the caller intact.
 */

export function useArchiveLesson() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            lessonId,
            idempotencyKey,
        }: {
            courseId: number;
            lessonId: number;
            idempotencyKey?: string;
        }): Promise<ArchiveOutcome> => {
            const { data } = await client.DELETE('/manage/lessons/{id}', {
                params: { path: { id: lessonId } },
                headers: idempotency(idempotencyKey),
            });
            return must(data).outcome;
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

export function useArchiveModule() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            moduleId,
            idempotencyKey,
        }: {
            courseId: number;
            moduleId: number;
            idempotencyKey?: string;
        }): Promise<ArchiveOutcome> => {
            const { data } = await client.DELETE('/manage/modules/{id}', {
                params: { path: { id: moduleId } },
                headers: idempotency(idempotencyKey),
            });
            return must(data).outcome;
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

/** The reverse of archiving, which is a plain state move on the update route. */
export function useRestoreLesson() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            lessonId,
            idempotencyKey,
        }: {
            courseId: number;
            lessonId: number;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/lessons/{id}', {
                params: { path: { id: lessonId } },
                body: { state: RESTORED_STATE },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

export function useRestoreModule() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            moduleId,
            idempotencyKey,
        }: {
            courseId: number;
            moduleId: number;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/modules/{id}', {
                params: { path: { id: moduleId } },
                body: { state: RESTORED_STATE },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

/* ── Quizzes and questions ──────────────────────────────────────────── */

export function useCourseQuizzes(courseId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: courseId !== null,
        queryKey: queryKeys.quizzes(courseId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/quizzes', {
                params: { path: { id: courseId as number } },
                signal,
            });
            return must(data).items;
        },
    });
}

/**
 * The one route on the management surface that carries correctness, and
 * only to a caller who may teach the course. It is what the question editor
 * reads and nothing else in this app requests it.
 */
export function useQuizAuthoring(quizId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: quizId !== null,
        queryKey: queryKeys.quiz(quizId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/quizzes/{id}', {
                params: { path: { id: quizId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useCreateQuiz() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            body: QuizSettingsRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/courses/{id}/quizzes', {
                params: { path: { id: courseId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

export function useUpdateQuiz() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            quizId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            quizId: number;
            body: QuizSettingsRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/quizzes/{id}', {
                params: { path: { id: quizId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (data, { courseId, quizId }) => {
            queryClient.setQueryData(queryKeys.quiz(quizId), data);
            invalidateCourse(queryClient, courseId);
        },
    });
}

export function useAddQuestion() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            quizId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            quizId: number;
            body: AddQuestionRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/quizzes/{id}/questions', {
                params: { path: { id: quizId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId, quizId }) => {
            void queryClient.invalidateQueries({ queryKey: queryKeys.quiz(quizId) });
            invalidateCourse(queryClient, courseId);
        },
    });
}

/** The type is fixed for the life of a question; only definition and points move. */
export function useUpdateQuestion() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            questionId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            quizId: number;
            questionId: number;
            body: UpdateQuestionRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/questions/{id}', {
                params: { path: { id: questionId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId, quizId }) => {
            void queryClient.invalidateQueries({ queryKey: queryKeys.quiz(quizId) });
            invalidateCourse(queryClient, courseId);
        },
    });
}

/**
 * A soft removal: the row stays so historical attempts keep their meaning,
 * and it stops counting for new ones.
 */
export function useRemoveQuestion() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            questionId,
            idempotencyKey,
        }: {
            courseId: number;
            quizId: number;
            questionId: number;
            idempotencyKey?: string;
        }) => {
            await client.DELETE('/manage/questions/{id}', {
                params: { path: { id: questionId } },
                headers: idempotency(idempotencyKey),
            });
        },
        onSuccess: (_data, { courseId, quizId }) => {
            void queryClient.invalidateQueries({ queryKey: queryKeys.quiz(quizId) });
            invalidateCourse(queryClient, courseId);
        },
    });
}

export function useReorderQuestions() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            quizId,
            questionIds,
            idempotencyKey,
        }: {
            courseId: number;
            quizId: number;
            questionIds: number[];
            idempotencyKey?: string;
        }) => {
            await client.PUT('/manage/quizzes/{id}/questions/order', {
                params: { path: { id: quizId } },
                body: { question_ids: questionIds },
                headers: idempotency(idempotencyKey),
            });
        },
        onSuccess: (_data, { quizId }) => {
            void queryClient.invalidateQueries({ queryKey: queryKeys.quiz(quizId) });
        },
        // A refused reorder is shown optimistically too, so the question list
        // has to come back from the server rather than keep the order the
        // server refused. See refetchCourseEitherWay.
        onError: (_error, { quizId }) => {
            void queryClient.invalidateQueries({ queryKey: queryKeys.quiz(quizId) });
        },
    });
}

/* ── Gradebook ──────────────────────────────────────────────────────── */

export function useGradebook(courseId: number, params: GradebookParams) {
    const client = useApiClient();
    return useQuery({
        queryKey: queryKeys.gradebook(courseId, params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/gradebook', {
                params: { path: { id: courseId }, query: params },
                signal,
            });
            return must(data);
        },
    });
}

/**
 * The course's grade structure, read for one fact this screen may not
 * compute for itself: which `GradeAggregation` provider produces the course
 * total, and whether it is installed.
 *
 * The arithmetic is a registration and never a switch, so the tab has no way
 * to work the total out and no business trying. Where the provider is
 * missing the contract answers `aggregation_available` false with the key
 * named, which is what the header shows instead of a number: a guessed total
 * is a grade dispute and an absent one is a support ticket.
 */
export function useGradeItems(courseId: number, enabled: boolean) {
    const client = useApiClient();
    return useQuery({
        queryKey: queryKeys.gradeItems(courseId),
        enabled,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/gradebook/items', {
                params: { path: { id: courseId } },
                signal,
            });
            return must(data);
        },
    });
}

/**
 * Grading a submission completes the assignment lesson through a
 * server-side listener, and the answer says what it did, which is the
 * sentence the grade button promised.
 */
export function useGradeSubmission() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            submissionId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            submissionId: number;
            body: GradeSubmissionRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/submissions/{id}/grade', {
                params: { path: { id: submissionId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

/**
 * One manual grade row per call; history is append-only, so re-grading adds
 * a row instead of rewriting one. The outcome says whether the attempt is
 * finished and how many answers still wait.
 */
export function useGradeAnswer() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            attemptId,
            questionId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            attemptId: number;
            questionId: number;
            body: GradeAnswerRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/attempts/{id}/answers/{question_id}/grade', {
                params: { path: { id: attemptId, question_id: questionId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

/* ── Learners ───────────────────────────────────────────────────────── */

export function useCourseLearners(courseId: number, params: LearnerParams) {
    const client = useApiClient();
    return useQuery({
        queryKey: queryKeys.learners(courseId, params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/learners', {
                params: { path: { id: courseId }, query: params },
                signal,
            });
            return must(data);
        },
    });
}

/**
 * The account picker behind the enrol dialog. Two characters minimum by
 * contract, so the query stays disabled below that instead of collecting
 * 422s.
 */
export function useEnrolLearner() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            body,
            idempotencyKey,
        }: {
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
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}

/** Suspends the enrolment when no other entitlement covers the course; progress survives. */
export function useRevokeEntitlement() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            entitlementId,
            idempotencyKey,
        }: {
            courseId: number;
            entitlementId: number;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/entitlements/{id}/revoke', {
                params: { path: { id: entitlementId } },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) => invalidateCourse(queryClient, courseId),
    });
}
