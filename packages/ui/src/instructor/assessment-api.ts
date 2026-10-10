import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import type {
    BulkUpdateQuestionBankEntriesRequest,
    CreateQuestionBankCategoryRequest,
    CreateQuestionBankEntryRequest,
    CreateQuestionBankRequest,
    QuestionBank,
    QuestionBankPage,
    UpdateQuestionBankRequest,
} from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/api';
import { idempotency, keepPrevious, must } from '@/shared/manage';
import { queryKeys as workbenchKeys } from './api';
import type {
    AddQuizSlotRequest,
    CreateBankEntryRequest,
    CreateBankEntryVersionRequest,
    CreateLearningObjectiveRequest,
    CreateQuestionCategoryRequest,
    CreateRubricRequest,
    OwnerScope,
    QuizPolicy,
    QuizSlotInput,
    RegradeRequest,
    RubricVersionRequest,
    StimulusRequest,
    UpdateBankEntryRequest,
    UpdateQuestionCategoryRequest,
    UpdateQuizSlotRequest,
    UpdateRubricRequest,
} from './lib/assessment-types';


/*
 * Every wave 3b call of the workbench, over the generated SDK, in its own
 * module.
 *
 * Same pattern as api.ts: one endpoint one hook, queries carry their key
 * from a keys object, mutations invalidate exactly what their write can have
 * changed, and the client rejects with ProblemError so one failure path
 * serves every screen. The SDK puts an Idempotency-Key on every write, and a
 * caller that retries a logical write passes its own key so the retry
 * replays instead of executing twice.
 *
 * It is a second module rather than an addition to api.ts because these are
 * a different surface with a different invalidation graph: a bank entry is
 * not scoped to a course, so the course branch of the workbench keys cannot
 * hold it, and a write to the bank must not refetch a course it never
 * touched.
 */

export interface BankEntryParams {
    /** Restrict to one owner scope; omitted, every scope the caller may see. */
    scope?: OwnerScope;
    scope_id?: number;
    /** Every scope reachable from this course at once, which is what the workbench sends. */
    course_id?: number;
    category_id?: number;
    include_subcategories?: boolean;
    q?: string;
    status?: 'draft' | 'ready' | 'archived';
    type?: string;
    tag?: string;
    objective_id?: number;
    difficulty?: string;
    page?: number;
    per_page?: number;
}

export interface RubricParams {
    scope?: 'site' | 'category' | 'course';
    scope_id?: number;
    course_id?: number;
    page?: number;
    per_page?: number;
}

export interface ItemAnalysisParams {
    quiz_id?: number;
    page?: number;
    per_page?: number;
}

export interface LearningObjectiveParams {
    framework?: string;
    q?: string;
    page?: number;
    per_page?: number;
}

/**
 * The keys of this surface. Bank, objective and rubric branches sit beside
 * the workbench's course branch rather than under it, because a folder in
 * the site scope belongs to no course and invalidating a course would not
 * reach it.
 */
export const assessmentKeys = {
    settingsSchema: (quizId: number) => ['vidyalms', 'manage', 'quiz', quizId, 'settings-schema'] as const,
    slots: (quizId: number) => ['vidyalms', 'manage', 'quiz', quizId, 'slots'] as const,
    bankRoot: ['vidyalms', 'manage', 'bank'] as const,
    categories: (scope: OwnerScope, scopeId: number | undefined) =>
        ['vidyalms', 'manage', 'bank', 'categories', scope, scopeId ?? null] as const,
    entries: (params: BankEntryParams) => ['vidyalms', 'manage', 'bank', 'entries', params] as const,
    entry: (entryId: number) => ['vidyalms', 'manage', 'bank', 'entry', entryId] as const,
    versions: (entryId: number) => ['vidyalms', 'manage', 'bank', 'entry', entryId, 'versions'] as const,
    usage: (entryId: number) => ['vidyalms', 'manage', 'bank', 'entry', entryId, 'usage'] as const,
    objectives: (q: string | undefined) => ['vidyalms', 'manage', 'objectives', q ?? ''] as const,
    objectivePages: (params: LearningObjectiveParams) =>
        ['vidyalms', 'manage', 'objectives', 'page', params] as const,
    stimuli: (courseId: number) => ['vidyalms', 'manage', 'course', courseId, 'stimuli'] as const,
    rubricRoot: ['vidyalms', 'manage', 'rubrics'] as const,
    rubrics: (params: RubricParams) => ['vidyalms', 'manage', 'rubrics', 'list', params] as const,
    rubric: (rubricId: number) => ['vidyalms', 'manage', 'rubrics', rubricId] as const,
    rubricVersions: (rubricId: number) => ['vidyalms', 'manage', 'rubrics', rubricId, 'versions'] as const,
    itemAnalysis: (courseId: number, params: ItemAnalysisParams) =>
        ['vidyalms', 'manage', 'course', courseId, 'item-analysis', params] as const,
    integrity: (attemptId: number) => ['vidyalms', 'manage', 'attempt', attemptId, 'integrity'] as const,
};

function invalidateQuiz(queryClient: QueryClient, quizId: number): void {
    void queryClient.invalidateQueries({ queryKey: workbenchKeys.quiz(quizId) });
    void queryClient.invalidateQueries({ queryKey: assessmentKeys.slots(quizId) });
}

/* ── The registry-driven settings schema ─────────────────────────────── */

/**
 * The policy fields this site's registries can offer for one quiz.
 *
 * The settings form is drawn from this answer and is never a hardcoded field
 * list: a new aggregation method or a new attempt behaviour appears in the
 * workbench with no client change, and a stored value naming a provider the
 * site no longer has comes back with `available` false and its reason rather
 * than reverting, so an author sees what the quiz was set to and what it is
 * running under.
 */
export function useQuizSettingsSchema(quizId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: quizId !== null,
        queryKey: assessmentKeys.settingsSchema(quizId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/quizzes/{id}/settings-schema', {
                params: { path: { id: quizId as number } },
                signal,
            });
            return must(data);
        },
    });
}

/* ── Slots ───────────────────────────────────────────────────────────── */

export function useQuizSlots(quizId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: quizId !== null,
        queryKey: assessmentKeys.slots(quizId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/quizzes/{id}/slots', {
                params: { path: { id: quizId as number } },
                signal,
            });
            return must(data);
        },
    });
}

/**
 * Replaces the whole tree in one write, which is what a structural move is:
 * a node changing parent or position moves every sibling index with it, and
 * three PATCHes that each land separately would leave the tree briefly
 * inconsistent and permanently wrong if one of them failed.
 */
export function useReplaceQuizSlots() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            quizId,
            slots,
            idempotencyKey,
        }: {
            quizId: number;
            slots: QuizSlotInput[];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/quizzes/{id}/slots', {
                params: { path: { id: quizId } },
                body: { slots },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (data, { quizId }) => {
            queryClient.setQueryData(assessmentKeys.slots(quizId), data);
            void queryClient.invalidateQueries({ queryKey: workbenchKeys.quiz(quizId) });
        },
        /* A refused structural change leaves the screen holding a tree the
           server never accepted, so the tree comes back from the server. */
        onError: (_error, { quizId }) => invalidateQuiz(queryClient, quizId),
    });
}

export function useAddQuizSlot() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            quizId,
            body,
            idempotencyKey,
        }: {
            quizId: number;
            body: AddQuizSlotRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/quizzes/{id}/slots', {
                params: { path: { id: quizId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { quizId }) => invalidateQuiz(queryClient, quizId),
    });
}

export function useUpdateQuizSlot() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            slotId,
            body,
            idempotencyKey,
        }: {
            quizId: number;
            slotId: number;
            body: UpdateQuizSlotRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/slots/{id}', {
                params: { path: { id: slotId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { quizId }) => invalidateQuiz(queryClient, quizId),
        onError: (_error, { quizId }) => invalidateQuiz(queryClient, quizId),
    });
}

/** Removing a section removes its children, which is why the tree is refetched whole. */
export function useRemoveQuizSlot() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            slotId,
            idempotencyKey,
        }: {
            quizId: number;
            slotId: number;
            idempotencyKey?: string;
        }) => {
            await client.DELETE('/manage/slots/{id}', {
                params: { path: { id: slotId } },
                headers: idempotency(idempotencyKey),
            });
        },
        onSuccess: (_data, { quizId }) => invalidateQuiz(queryClient, quizId),
    });
}

/* ── Banks ───────────────────────────────────────────────────────────── */

/**
 * The folder tree of one owner scope, roots first.
 *
 * The scope is required by the route because it decides who may see the
 * questions inside: the site scope answers 403 to a caller who only teaches,
 * so the tree is asked for one scope at a time rather than all at once.
 */
export function useQuestionCategories(scope: OwnerScope, scopeId: number | undefined) {
    const client = useApiClient();
    return useQuery({
        queryKey: assessmentKeys.categories(scope, scopeId),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/banks/categories', {
                params: { query: scopeId === undefined ? { scope } : { scope, scope_id: scopeId } },
                signal,
            });
            return must(data).items;
        },
    });
}

export function useCreateQuestionCategory() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            body,
            idempotencyKey,
        }: {
            body: CreateQuestionCategoryRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/banks/categories', {
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.bankRoot }),
    });
}

/** The owner scope is fixed for the life of a folder; only the name, the parent and the position move. */
export function useUpdateQuestionCategory() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            categoryId,
            body,
            idempotencyKey,
        }: {
            categoryId: number;
            body: UpdateQuestionCategoryRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/banks/categories/{id}', {
                params: { path: { id: categoryId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.bankRoot }),
    });
}

export function useRemoveQuestionCategory() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ categoryId, idempotencyKey }: { categoryId: number; idempotencyKey?: string }) => {
            await client.DELETE('/manage/banks/categories/{id}', {
                params: { path: { id: categoryId } },
                headers: idempotency(idempotencyKey),
            });
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.bankRoot }),
    });
}

export function useBankEntries(params: BankEntryParams, enabled = true) {
    const client = useApiClient();
    return useQuery({
        enabled,
        queryKey: assessmentKeys.entries(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/banks/entries', { params: { query: params }, signal });
            return must(data);
        },
    });
}

export function useBankEntry(entryId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: entryId !== null,
        queryKey: assessmentKeys.entry(entryId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/banks/entries/{id}', {
                params: { path: { id: entryId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useBankEntryVersions(entryId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: entryId !== null,
        queryKey: assessmentKeys.versions(entryId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/banks/entries/{id}/versions', {
                params: { path: { id: entryId as number } },
                signal,
            });
            return must(data).items;
        },
    });
}

/** Which quizzes place this entry, at which version, and whether by draw or by name. */
export function useBankEntryUsage(entryId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: entryId !== null,
        queryKey: assessmentKeys.usage(entryId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/banks/entries/{id}/usage', {
                params: { path: { id: entryId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useCreateBankEntry() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            body,
            idempotencyKey,
        }: {
            body: CreateBankEntryRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/banks/entries', {
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.bankRoot }),
    });
}

/** The entry's own fields only. Editing the question is a new version and never this. */
export function useUpdateBankEntry() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            entryId,
            body,
            idempotencyKey,
        }: {
            entryId: number;
            body: UpdateBankEntryRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/banks/entries/{id}', {
                params: { path: { id: entryId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.bankRoot }),
    });
}

/**
 * Archives rather than deletes: nothing on this surface removes an authored
 * question, so the answer is the entry in its new state and a slot already
 * pointing at it keeps resolving.
 */
export function useArchiveBankEntry() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ entryId, idempotencyKey }: { entryId: number; idempotencyKey?: string }) => {
            const { data } = await client.DELETE('/manage/banks/entries/{id}', {
                params: { path: { id: entryId } },
                headers: idempotency(idempotencyKey),
            });
            return data ?? null;
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.bankRoot }),
    });
}

/**
 * Editing a question that has been answered never mutates in place: it
 * writes a new version, and attempts already begun keep the version they
 * were served.
 */
export function useCreateBankEntryVersion() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            entryId,
            body,
            idempotencyKey,
        }: {
            entryId: number;
            body: CreateBankEntryVersionRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/banks/entries/{id}/versions', {
                params: { path: { id: entryId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.bankRoot }),
    });
}

export function useSetBankEntryTags() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            entryId,
            tags,
            idempotencyKey,
        }: {
            entryId: number;
            tags: string[];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/banks/entries/{id}/tags', {
                params: { path: { id: entryId } },
                body: { tags },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.bankRoot }),
    });
}

export function useSetBankEntryObjectives() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            entryId,
            objectiveIds,
            idempotencyKey,
        }: {
            entryId: number;
            objectiveIds: number[];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/banks/entries/{id}/objectives', {
                params: { path: { id: entryId } },
                body: { objective_ids: objectiveIds },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.bankRoot }),
    });
}

export function useLearningObjectives(q?: string) {
    const client = useApiClient();
    return useQuery({
        queryKey: assessmentKeys.objectives(q),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/objectives', {
                params: { query: q === undefined || q === '' ? {} : { q } },
                signal,
            });
            return must(data).items;
        },
    });
}

/** One bounded, searchable page for label-based objective pickers. */
export function useLearningObjectivePage(params: LearningObjectiveParams, enabled = true) {
    const client = useApiClient();
    return useQuery({
        enabled,
        queryKey: assessmentKeys.objectivePages(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/objectives', {
                params: { query: { ...params } },
                signal,
            });
            return must(data);
        },
    });
}

export function useCreateLearningObjective() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            body,
            idempotencyKey,
        }: {
            body: CreateLearningObjectiveRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/objectives', {
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.objectives(undefined) }),
    });
}

/* ── Stimuli ─────────────────────────────────────────────────────────── */

export function useCourseStimuli(courseId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: courseId !== null,
        queryKey: assessmentKeys.stimuli(courseId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/stimuli', {
                params: { path: { id: courseId as number } },
                signal,
            });
            return must(data).items;
        },
    });
}

export function useCreateStimulus() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            courseId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            body: StimulusRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/courses/{id}/stimuli', {
                params: { path: { id: courseId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) =>
            void queryClient.invalidateQueries({ queryKey: assessmentKeys.stimuli(courseId) }),
    });
}

export function useUpdateStimulus() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            stimulusId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            stimulusId: number;
            body: StimulusRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/stimuli/{id}', {
                params: { path: { id: stimulusId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId }) =>
            void queryClient.invalidateQueries({ queryKey: assessmentKeys.stimuli(courseId) }),
    });
}

/* ── Rubrics ─────────────────────────────────────────────────────────── */

export function useRubrics(params: RubricParams, enabled = true) {
    const client = useApiClient();
    return useQuery({
        enabled,
        queryKey: assessmentKeys.rubrics(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/rubrics', { params: { query: params }, signal });
            return must(data);
        },
    });
}

export function useRubric(rubricId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: rubricId !== null,
        queryKey: assessmentKeys.rubric(rubricId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/rubrics/{id}', {
                params: { path: { id: rubricId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useRubricVersions(rubricId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: rubricId !== null,
        queryKey: assessmentKeys.rubricVersions(rubricId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/rubrics/{id}/versions', {
                params: { path: { id: rubricId as number } },
                signal,
            });
            return must(data).items;
        },
    });
}

export function useCreateRubric() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ body, idempotencyKey }: { body: CreateRubricRequest; idempotencyKey?: string }) => {
            const { data } = await client.POST('/manage/rubrics', {
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.rubricRoot }),
    });
}

/** The rubric's own fields. Editing criteria or levels is a new version, because a grade records the level a rater chose. */
export function useUpdateRubric() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            rubricId,
            body,
            idempotencyKey,
        }: {
            rubricId: number;
            body: UpdateRubricRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/rubrics/{id}', {
                params: { path: { id: rubricId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.rubricRoot }),
    });
}

export function useCreateRubricVersion() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            rubricId,
            body,
            idempotencyKey,
        }: {
            rubricId: number;
            body: RubricVersionRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/rubrics/{id}/versions', {
                params: { path: { id: rubricId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: assessmentKeys.rubricRoot }),
    });
}

/* ── Regrade ─────────────────────────────────────────────────────────── */

/**
 * A regrade appends and never mutates, so a dry run and the real thing are
 * the same computation and the answer says exactly what moved.
 *
 * Nothing is invalidated after a dry run: it wrote nothing, and refetching
 * the course would say otherwise.
 */
export function useRegradeQuiz() {
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
            body: RegradeRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/quizzes/{id}/regrade', {
                params: { path: { id: quizId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId, body }) => {
            if (body.dry_run !== true) {
                void queryClient.invalidateQueries({ queryKey: workbenchKeys.course(courseId) });
            }
        },
    });
}

export function useRegradeAttempt() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            attemptId,
            body,
            idempotencyKey,
        }: {
            courseId: number;
            attemptId: number;
            body: RegradeRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/attempts/{id}/regrade', {
                params: { path: { id: attemptId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_data, { courseId, body }) => {
            if (body.dry_run !== true) {
                void queryClient.invalidateQueries({ queryKey: workbenchKeys.course(courseId) });
            }
        },
    });
}

/* ── Reports ─────────────────────────────────────────────────────────── */

/**
 * Facility, discrimination and distractor rates.
 *
 * The inputs are recorded by every build; the computation is a provider. A
 * site with none answers 200 with `computed` false, an empty list and a
 * reason, so the screen renders its placeholder rather than an error, and
 * installing one later reports on every attempt already taken.
 */
export function useItemAnalysis(courseId: number, params: ItemAnalysisParams) {
    const client = useApiClient();
    return useQuery({
        queryKey: assessmentKeys.itemAnalysis(courseId, params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/reports/item-analysis', {
                params: { path: { id: courseId }, query: params },
                signal,
            });
            return must(data);
        },
    });
}

/** The advisory rows a human reads; nothing in the core acts on one. */
export function useIntegrityEvents(attemptId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: attemptId !== null,
        queryKey: assessmentKeys.integrity(attemptId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/attempts/{id}/integrity-events', {
                params: { path: { id: attemptId as number } },
                signal,
            });
            return must(data);
        },
    });
}

/* ── Quiz policy ─────────────────────────────────────────────────────── */

/**
 * The policy half of the quiz settings write.
 *
 * PUT /manage/quizzes/{id} is a full replacement by contract, so the policy
 * travels with the quiz's own fields rather than on a route of its own; this
 * hook exists so the policy form can send its object without restating the
 * quiz fields it does not own.
 */
export interface QuizPolicyWrite {
    courseId: number;
    quizId: number;
    /** The quiz fields exactly as they stand, so the replacement replaces nothing else. */
    settings: {
        title: string;
        lesson_id?: number | null;
        description?: string | null;
        pass_mark?: number;
        max_attempts?: number | null;
        time_limit_seconds?: number | null;
        is_final?: boolean;
        show_results?: 'after_submit' | 'never';
    };
    policy: QuizPolicy;
    idempotencyKey?: string;
}

export function useSaveQuizPolicy() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ quizId, settings, policy, idempotencyKey }: QuizPolicyWrite) => {
            const { data } = await client.PUT('/manage/quizzes/{id}', {
                params: { path: { id: quizId } },
                body: { ...settings, policy },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (data, { courseId, quizId }) => {
            queryClient.setQueryData(workbenchKeys.quiz(quizId), data);
            void queryClient.invalidateQueries({ queryKey: workbenchKeys.course(courseId) });
            void queryClient.invalidateQueries({ queryKey: assessmentKeys.settingsSchema(quizId) });
        },
    });
}

/* ── Named question banks (Stage 4 admin surface) ───────────────────── */

export type QuestionBankState = QuestionBank['state'];
export type { QuestionBank, QuestionBankPage, CreateQuestionBankRequest, UpdateQuestionBankRequest };

export interface QuestionBankListParams {
    q?: string;
    state?: QuestionBankState;
    owner_scope?: OwnerScope;
    owner_id?: number;
    sort?: 'name' | 'updated_at' | 'created_at' | 'question_count';
    order?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

export interface QuestionBankEntryParams {
    q?: string;
    category_id?: number;
    include_subcategories?: boolean;
    status?: 'draft' | 'ready' | 'archived';
    type?: string;
    tag?: string;
    objective_id?: number;
    difficulty?: string;
    usage?: 'any' | 'used' | 'unused';
    created_by?: number;
    last_used_before?: string;
    sort?: 'updated_at' | 'created_at' | 'type' | 'status' | 'difficulty' | 'usage_count';
    order?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

export type { CreateQuestionBankCategoryRequest, CreateQuestionBankEntryRequest };

export const questionBankKeys = {
    root: ['vidyalms', 'manage', 'question-banks'] as const,
    lists: () => ['vidyalms', 'manage', 'question-banks', 'list'] as const,
    list: (params: QuestionBankListParams) => [...questionBankKeys.lists(), params] as const,
    detail: (bankId: number) => [...questionBankKeys.root, bankId] as const,
    categories: (bankId: number) => [...questionBankKeys.detail(bankId), 'categories'] as const,
    entries: (bankId: number, params: QuestionBankEntryParams) =>
        [...questionBankKeys.detail(bankId), 'entries', params] as const,
    entry: (bankId: number, entryId: number) =>
        [...questionBankKeys.detail(bankId), 'entries', entryId] as const,
    versions: (bankId: number, entryId: number) =>
        [...questionBankKeys.entry(bankId, entryId), 'versions'] as const,
    usage: (bankId: number, entryId: number) =>
        [...questionBankKeys.entry(bankId, entryId), 'usage'] as const,
};

function invalidateQuestionBank(queryClient: QueryClient, bankId?: number): void {
    void queryClient.invalidateQueries({ queryKey: questionBankKeys.lists() });
    if (bankId !== undefined) {
        void queryClient.invalidateQueries({ queryKey: questionBankKeys.detail(bankId) });
    }
}

export function useQuestionBanks(params: QuestionBankListParams) {
    const client = useApiClient();
    return useQuery({
        queryKey: questionBankKeys.list(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/banks', {
                params: { query: { ...params } },
                signal,
            });
            return must(data);
        },
    });
}

export function useQuestionBank(bankId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: bankId !== null,
        queryKey: questionBankKeys.detail(bankId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/banks/{bank_id}', {
                params: { path: { bank_id: bankId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useCreateQuestionBank() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ body, idempotencyKey }: { body: CreateQuestionBankRequest; idempotencyKey?: string }) => {
            const { data } = await client.POST('/manage/banks', {
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: () => invalidateQuestionBank(queryClient),
    });
}

export function useUpdateQuestionBank() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            body,
            idempotencyKey,
        }: {
            bankId: number;
            body: UpdateQuestionBankRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/banks/{bank_id}', {
                params: { path: { bank_id: bankId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (bank) => {
            queryClient.setQueryData(questionBankKeys.detail(bank.id), bank);
            invalidateQuestionBank(queryClient, bank.id);
        },
    });
}

export function useArchiveQuestionBank() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ bankId, idempotencyKey }: { bankId: number; idempotencyKey?: string }) => {
            const { data } = await client.PATCH('/manage/banks/{bank_id}', {
                params: { path: { bank_id: bankId } },
                body: { state: 'archived' },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (bank) => invalidateQuestionBank(queryClient, bank.id),
    });
}

export function useQuestionBankCategories(bankId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: bankId !== null,
        queryKey: questionBankKeys.categories(bankId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET(
                '/manage/banks/{bank_id}/categories',
                { params: { path: { bank_id: bankId as number } }, signal },
            );
            return must(data).items;
        },
    });
}

export function useCreateQuestionBankCategory() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            body,
            idempotencyKey,
        }: {
            bankId: number;
            body: CreateQuestionBankCategoryRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/banks/{bank_id}/categories', {
                params: { path: { bank_id: bankId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_category, { bankId }) => invalidateQuestionBank(queryClient, bankId),
    });
}

export function useUpdateQuestionBankCategory() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            categoryId,
            body,
            idempotencyKey,
        }: {
            bankId: number;
            categoryId: number;
            body: UpdateQuestionCategoryRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH(
                '/manage/banks/{bank_id}/categories/{id}',
                {
                    params: { path: { bank_id: bankId, id: categoryId } },
                    body,
                    headers: idempotency(idempotencyKey),
                },
            );
            return must(data);
        },
        onSuccess: (_category, { bankId }) => invalidateQuestionBank(queryClient, bankId),
    });
}

export function useRemoveQuestionBankCategory() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            categoryId,
            idempotencyKey,
        }: {
            bankId: number;
            categoryId: number;
            idempotencyKey?: string;
        }) => {
            await client.DELETE('/manage/banks/{bank_id}/categories/{id}', {
                params: { path: { bank_id: bankId, id: categoryId } },
                headers: idempotency(idempotencyKey),
            });
        },
        onSuccess: (_unused, { bankId }) => invalidateQuestionBank(queryClient, bankId),
    });
}

export function useQuestionBankEntries(bankId: number | null, params: QuestionBankEntryParams) {
    const client = useApiClient();
    return useQuery({
        enabled: bankId !== null,
        queryKey: questionBankKeys.entries(bankId ?? 0, params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/banks/{bank_id}/entries', {
                params: { path: { bank_id: bankId as number }, query: { ...params } },
                signal,
            });
            return must(data);
        },
    });
}

/** Explicit ids only. This hook never turns a page selection into “all matching”. */
export function useBulkUpdateQuestionBankEntries() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            body,
            idempotencyKey,
        }: {
            bankId: number;
            body: BulkUpdateQuestionBankEntriesRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/banks/{bank_id}/entries', {
                params: { path: { bank_id: bankId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_result, { bankId }) => invalidateQuestionBank(queryClient, bankId),
    });
}

export function useQuestionBankEntry(bankId: number | null, entryId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: bankId !== null && entryId !== null,
        queryKey: questionBankKeys.entry(bankId ?? 0, entryId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/banks/{bank_id}/entries/{id}', {
                params: { path: { bank_id: bankId as number, id: entryId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useCreateQuestionBankEntry() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            body,
            idempotencyKey,
        }: {
            bankId: number;
            body: CreateQuestionBankEntryRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/banks/{bank_id}/entries', {
                params: { path: { bank_id: bankId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_entry, { bankId }) => invalidateQuestionBank(queryClient, bankId),
    });
}

export function useUpdateQuestionBankEntry() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            entryId,
            body,
            idempotencyKey,
        }: {
            bankId: number;
            entryId: number;
            body: UpdateBankEntryRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PATCH('/manage/banks/{bank_id}/entries/{id}', {
                params: { path: { bank_id: bankId, id: entryId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_entry, { bankId }) => invalidateQuestionBank(queryClient, bankId),
    });
}

export function useArchiveQuestionBankEntry() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            entryId,
            idempotencyKey,
        }: {
            bankId: number;
            entryId: number;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.DELETE('/manage/banks/{bank_id}/entries/{id}', {
                params: { path: { bank_id: bankId, id: entryId } },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_entry, { bankId }) => invalidateQuestionBank(queryClient, bankId),
    });
}

export function useCreateQuestionBankEntryVersion() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            entryId,
            body,
            idempotencyKey,
        }: {
            bankId: number;
            entryId: number;
            body: CreateBankEntryVersionRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST(
                '/manage/banks/{bank_id}/entries/{id}/versions',
                {
                    params: { path: { bank_id: bankId, id: entryId } },
                    body,
                    headers: idempotency(idempotencyKey),
                },
            );
            return must(data);
        },
        onSuccess: (_version, { bankId }) => invalidateQuestionBank(queryClient, bankId),
    });
}

export function useQuestionBankEntryVersions(bankId: number | null, entryId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: bankId !== null && entryId !== null,
        queryKey: questionBankKeys.versions(bankId ?? 0, entryId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET(
                '/manage/banks/{bank_id}/entries/{id}/versions',
                { params: { path: { bank_id: bankId as number, id: entryId as number } }, signal },
            );
            return must(data).items;
        },
    });
}

export function useQuestionBankEntryUsage(bankId: number | null, entryId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: bankId !== null && entryId !== null,
        queryKey: questionBankKeys.usage(bankId ?? 0, entryId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/banks/{bank_id}/entries/{id}/usage', {
                params: { path: { bank_id: bankId as number, id: entryId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useSetQuestionBankEntryTags() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            entryId,
            tags,
            idempotencyKey,
        }: {
            bankId: number;
            entryId: number;
            tags: string[];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT('/manage/banks/{bank_id}/entries/{id}/tags', {
                params: { path: { bank_id: bankId, id: entryId } },
                body: { tags },
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: (_entry, { bankId }) => invalidateQuestionBank(queryClient, bankId),
    });
}

export function useSetQuestionBankEntryObjectives() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            bankId,
            entryId,
            objectiveIds,
            idempotencyKey,
        }: {
            bankId: number;
            entryId: number;
            objectiveIds: number[];
            idempotencyKey?: string;
        }) => {
            const { data } = await client.PUT(
                '/manage/banks/{bank_id}/entries/{id}/objectives',
                {
                    params: { path: { bank_id: bankId, id: entryId } },
                    body: { objective_ids: objectiveIds },
                    headers: idempotency(idempotencyKey),
                },
            );
            return must(data);
        },
        onSuccess: (_entry, { bankId }) => invalidateQuestionBank(queryClient, bankId),
    });
}
