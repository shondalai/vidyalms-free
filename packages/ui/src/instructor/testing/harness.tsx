import type { ReactNode } from 'react';
import { MOUNT_CONTRACT } from '@/shared/config';
import type { QueryClient } from '@tanstack/react-query';
import { QueryClientProvider } from '@tanstack/react-query';
import { createVidyaLmsClient, type FetchLike } from '@vidyalms/api-sdk';
import type {
    AuthoringCurriculum,
    AuthoringLesson,
    AuthoringModule,
    AuthoringQuestion,
    EnrolmentRecord,
    GradebookItem,
    GradebookPage,
    ManagedCourseDetail,
    QuestionTypeInfo,
    QuizAuthoring,
    QuizSummary,
} from '@vidyalms/api-sdk';
import type { QuestionPresetInfo } from '../lib/question-picker';
import { ApiClientContext } from '@/shared/context';
import { SessionTracker, SessionTrackerContext, withSessionTracking } from '@/shared/session';
import { createAppQueryClient } from '@/shared/query';
import { InstructorConfigContext, type InstructorConfig } from '../config';

/*
 * Test-only harness of the workbench suites: the mount config, an SDK
 * client over a stubbed fetch (the SDK boundary is the mock seam; no
 * network), and fixtures shaped exactly like the contract's answers.
 */

export function makeConfig(overrides: Partial<InstructorConfig> = {}): InstructorConfig {
    return {
        /* Absolute base: Node's Request rejects relative URLs. */
        contract: MOUNT_CONTRACT,
        api: { base: 'http://localhost/v1', mode: 'prefix', headers: { 'X-CSRF-Token': 'test' } },
        course: { id: 1, slug: 'web-accessibility' },
        user: { id: 7, name: 'R. Okafor' },
        locale: 'en-GB',
        routes: ['/manage/courses/{id}/cohorts', '/manage/courses/{id}/reports/item-analysis'],
        urls: {
            courses: '/admin/courses',
            workbench: '/admin/courses/{course_id}',
            preview: '/course/{slug}',
        },
        ...overrides,
    };
}

export interface RecordedRequest {
    method: string;
    path: string;
    body: unknown;
    headers: Record<string, string>;
}

export type RouteHandler = (request: RecordedRequest) => Response | null;

export interface Harness {
    wrapper: (props: { children: ReactNode }) => ReactNode;
    requests: RecordedRequest[];
    queryClient: QueryClient;
    config: InstructorConfig;
    sessionTracker: SessionTracker;
}

/**
 * Routes requests by "METHOD pathname" through the handler; a null answer
 * falls through to 404. Bodies are recorded parsed, and headers are kept so
 * a suite can prove the Idempotency-Key of a retry.
 */
export function makeHarness(handler: RouteHandler, config: InstructorConfig = makeConfig()): Harness {
    const requests: RecordedRequest[] = [];
    const fetchLike: FetchLike = async (request) => {
        const url = new URL(request.url, 'http://localhost/');
        const raw = request.method === 'GET' || request.method === 'HEAD' ? null : await request.text();
        const headers: Record<string, string> = {};
        request.headers.forEach((value, key) => {
            headers[key.toLowerCase()] = value;
        });
        const recorded: RecordedRequest = {
            method: request.method,
            path: url.pathname + url.search,
            body: raw !== null && raw !== '' ? JSON.parse(raw) : null,
            headers,
        };
        requests.push(recorded);
        return (
            handler(recorded) ??
            new Response(JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }), {
                status: 404,
                headers: { 'Content-Type': 'application/problem+json' },
            })
        );
    };
    const sessionTracker = new SessionTracker();
    const client = createVidyaLmsClient(config.api, withSessionTracking(sessionTracker, fetchLike));
    // The shipped configuration, not a friendlier one. A harness that
    // retried differently from the bundle could not see a screen that keeps
    // a skeleton up while a refusal is retried, which is how that shipped.
    const queryClient = createAppQueryClient();
    const wrapper = ({ children }: { children: ReactNode }) => (
        <InstructorConfigContext.Provider value={config}>
            <SessionTrackerContext.Provider value={sessionTracker}>
                <ApiClientContext.Provider value={client}>
                    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
                </ApiClientContext.Provider>
            </SessionTrackerContext.Provider>
        </InstructorConfigContext.Provider>
    );
    return { wrapper, requests, queryClient, config, sessionTracker };
}

export function problemResponse(status: number, title = 'Problem', detail?: string): Response {
    return new Response(JSON.stringify({ type: 'about:blank', title, status, detail }), {
        status,
        headers: { 'Content-Type': 'application/problem+json' },
    });
}

export function jsonResponse(payload: unknown, status = 200): Response {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

export function noContent(): Response {
    return new Response(null, { status: 204 });
}

/* ── Fixtures ───────────────────────────────────────────────────────── */

export const lessonTypesFixture = [
    { key: 'video', label: 'Video embed', completion_mode: 'view' as const },
    { key: 'reading', label: 'Reading', completion_mode: 'view' as const },
    { key: 'resource', label: 'Resource', completion_mode: 'view' as const },
    { key: 'assignment', label: 'Assignment', completion_mode: 'submission' as const },
    { key: 'quiz', label: 'Quiz', completion_mode: 'pass' as const },
];

/*
 * The six a free site answers, with the family and the sort position each
 * type declares. The order below is registration order on purpose: the
 * contract says the picker sorts on the declared group and order rather than
 * on the order the registry hands them over, and fill_blank really is
 * registered before short_answer.
 */
export const questionTypesFixture: QuestionTypeInfo[] = [
    questionType('single_choice', 'Single choice', 'selection', 10, { response_shape: 'single:identifier' }),
    questionType('multiple_choice', 'Multiple choice', 'selection', 20, { response_shape: 'multiple:identifier' }),
    questionType('true_false', 'True or false', 'selection', 30, { response_shape: 'single:boolean' }),
    questionType('fill_blank', 'Fill in the blank', 'text_entry', 20, { response_shape: 'record' }),
    questionType('short_answer', 'Short answer', 'text_entry', 10, { response_shape: 'single:string' }),
    questionType('ordering', 'Ordering', 'association', 10, { response_shape: 'ordered:identifier' }),
];

export function questionType(
    key: string,
    label: string,
    group: QuestionTypeInfo['group'],
    order: number,
    overrides: Partial<QuestionTypeInfo> = {},
): QuestionTypeInfo {
    return {
        key,
        label,
        group,
        order,
        response_shape: null,
        offerable_policies: [],
        policy_field: 'policy',
        authoring: {
            definition_version: 1,
            supported_definition_versions: [1],
            definition_schema: {},
            primitive: null,
            surface: 'flow',
            scoring_policies: [],
            accessibility: {
                interaction_modes: ['POINTER_NO_DRAG', 'KEYBOARD', 'LINEAR_TEXT'],
                essential_dragging_justification: null,
                conformance_result: 'test-fixture',
                verified: true,
            },
        },
        ...overrides,
    };
}

/*
 * The six aliases the free build registers over its own types: one per type,
 * carrying the author-facing name and the foreign identifiers an importer
 * selects that target with. A preset creates no provider key and stores no
 * value in questions.type.
 */
export const questionPresetsFixture: QuestionPresetInfo[] = [
    preset('single_choice', 'Multiple choice, one answer', 'selection', 'single_choice', 'match_correct'),
    preset('multiple_choice', 'Multiple response', 'selection', 'multiple_choice', 'guess_neutral'),
    preset('true_false', 'True or false', 'selection', 'true_false', 'match_correct'),
    preset('short_answer', 'Short answer', 'text_entry', 'short_answer', 'text_match'),
    preset('fill_blank', 'Fill in the blank', 'text_entry', 'fill_blank', 'per_target'),
    preset('ordering', 'Sequencing', 'association', 'ordering', 'kendall_tau'),
];

export function preset(
    key: string,
    label: string,
    group: QuestionPresetInfo['group'],
    baseType: string,
    policy: string,
    skeleton: Record<string, unknown> = {},
    aliases: string[] = [],
): QuestionPresetInfo {
    return {
        preset_key: key,
        label,
        group,
        base_type_key: baseType,
        default_policy: policy,
        definition_skeleton: skeleton,
        source_aliases: aliases,
        help_url: null,
    };
}

export function makeCourse(overrides: Partial<ManagedCourseDetail> = {}): ManagedCourseDetail {
    return {
        id: 1,
        title: 'Foundations of Web Accessibility',
        slug: 'web-accessibility',
        summary: null,
        image: null,
        category_id: null,
        level: null,
        language: 'en-GB',
        visibility: 'public',
        state: 'published',
        price_mode: 'free',
        featured: false,
        completion_rule: 'all_lessons',
        completion_threshold: null,
        published_at: '2026-06-01 10:00:00',
        updated_at: '2026-08-30 09:00:00',
        lesson_count: 6,
        archived_lesson_count: 0,
        enrolled_count: 128,
        active_count: 108,
        completed_count: 20,
        suspended_count: 8,
        completion_rate: 15.6,
        instructors: [{ user_id: 7, name: 'R. Okafor', role: 'lead' }],
        description: null,
        created_by: 7,
        created_at: '2026-05-01 09:00:00',
        prerequisites: [],
        pending_grading_count: 8,
        enrolment_rule: 'open',
        progression: 'free',
        timezone: null,
        starts_at: null,
        late_policy_key: 'none',
        late_policy_params: null,
        ...overrides,
    };
}

/*
 * The state of a module or a lesson is widened here on purpose. Archiving
 * moves a row to "archived", which the generated schema does not carry yet
 * (the contract change is in flight), and a fixture that could not express
 * it could not cover the screen that shows it. The cast is this one place.
 */
export type AuthoringState = 'published' | 'draft' | 'archived';

export function makeLesson(
    overrides: Omit<Partial<AuthoringLesson>, 'state'> & { id: number; state?: AuthoringState },
): AuthoringLesson {
    return {
        course_id: 1,
        module_id: 2,
        title: 'Text alternatives',
        slug: `lesson-${overrides.id}`,
        type: 'video',
        summary: null,
        duration_seconds: 300,
        position: 1,
        state: 'published',
        free_preview: false,
        content: { provider: 'youtube', url: 'https://youtu.be/abc', caption_url: null },
        content_available: true,
        quiz: null,
        assignment: null,
        ...overrides,
    } as AuthoringLesson;
}

export function makeModule(
    overrides: Omit<Partial<AuthoringModule>, 'state'> & { id: number; state?: AuthoringState },
): AuthoringModule {
    return {
        course_id: 1,
        title: 'Getting started',
        summary: null,
        position: 1,
        state: 'published',
        lessons: [],
        ...overrides,
    } as AuthoringModule;
}

export function makeCurriculum(overrides: Partial<AuthoringCurriculum> = {}): AuthoringCurriculum {
    return {
        course_id: 1,
        modules: [
            makeModule({
                id: 1,
                title: 'Getting started',
                position: 1,
                lessons: [makeLesson({ id: 1, module_id: 1, title: 'Welcome', type: 'reading' })],
            }),
            makeModule({
                id: 2,
                title: 'Perceivable content',
                position: 2,
                lessons: [
                    makeLesson({ id: 2, module_id: 2, title: 'Text alternatives' }),
                    makeLesson({ id: 3, module_id: 2, title: 'Colour checklist', type: 'resource', state: 'draft' }),
                ],
            }),
        ],
        ...overrides,
    };
}

export function makeQuiz(overrides: Partial<QuizSummary> = {}): QuizSummary {
    return {
        id: 21,
        course_id: 1,
        lesson_id: 4,
        lesson: { id: 4, title: 'Module 2 check', module_id: 2, module_title: 'Perceivable content' },
        title: 'Module 2 check',
        description: null,
        pass_mark: 70,
        max_attempts: 3,
        time_limit_seconds: 1200,
        is_final: false,
        show_results: 'after_submit',
        question_count: 2,
        ...overrides,
    };
}

export function makeQuestion(overrides: Partial<AuthoringQuestion> & { id: number }): AuthoringQuestion {
    return {
        quiz_id: 21,
        type: 'single_choice',
        position: 1,
        points: 1,
        definition: {
            prompt: 'Which of these is the primary audience for closed captions?',
            options: [
                { key: 'opt1', label: 'People watching in sound-off environments' },
                { key: 'opt2', label: 'Deaf and hard-of-hearing viewers' },
            ],
            correct: 'opt2',
        },
        ...overrides,
    };
}

export function makeQuizAuthoring(overrides: Partial<QuizAuthoring> = {}): QuizAuthoring {
    return {
        ...makeQuiz(),
        questions: [
            makeQuestion({ id: 101 }),
            makeQuestion({
                id: 102,
                type: 'short_answer',
                position: 2,
                definition: { prompt: 'Why should focus return to the trigger?', accepted: [] },
            }),
        ],
        ...overrides,
    };
}

/*
 * lesson_id is widened the same way: the contract is making it nullable
 * (an item whose lesson is gone), and the gradebook has to be proven to
 * render such a row rather than to have been lucky.
 */
export type GradebookOverrides = Omit<Partial<GradebookItem>, 'lesson_id'> & { lesson_id?: number | null };

export function makeSubmissionItem(overrides: GradebookOverrides = {}): GradebookItem {
    return {
        key: 'submission:31',
        type: 'submission',
        status: 'pending',
        learner: { id: 41, name: 'Priya Nair' },
        course_id: 1,
        lesson_id: 9,
        lesson_title: 'Accessibility audit exercise',
        waiting_since: '2026-08-28 14:32:00',
        grade: null,
        submission: {
            id: 31,
            assignment_id: 5,
            enrolment_id: 3,
            attempt_number: 1,
            text_response: null,
            submitted_at: '2026-08-28 14:32:00',
            max_points: 100,
            allow_resubmission: true,
            files: [
                {
                    filename: 'audit-report-priya.zip',
                    size_bytes: 2202009,
                    mime: 'application/zip',
                    url: 'https://example.test/signed/audit.zip',
                },
            ],
        },
        quiz_answer: null,
        ...overrides,
    } as GradebookItem;
}

export function makeAnswerItem(overrides: GradebookOverrides = {}): GradebookItem {
    return {
        key: 'answer:71:102',
        type: 'quiz_answer',
        status: 'pending',
        learner: { id: 42, name: 'Tom Weiss' },
        course_id: 1,
        lesson_id: 4,
        lesson_title: 'Module 2 check',
        waiting_since: '2026-09-01 08:00:00',
        grade: null,
        submission: null,
        quiz_answer: {
            attempt_id: 71,
            quiz_id: 21,
            quiz_title: 'Module 2 check',
            attempt_number: 1,
            question: {
                id: 102,
                position: 8,
                type: 'short_answer',
                points: 1,
                presentation: { prompt: 'Why should focus return to the trigger?' },
            },
            answer: 'So the keyboard does not lose its place.',
            answered_at: '2026-09-01 08:00:00',
        },
        ...overrides,
    } as GradebookItem;
}

export function makeGradebookPage(overrides: Partial<GradebookPage> = {}): GradebookPage {
    const items = overrides.items ?? [makeSubmissionItem(), makeAnswerItem()];
    return {
        items,
        total: items.length,
        page: 1,
        per_page: 20,
        pending_total: items.filter((item) => item.status === 'pending').length,
        graded_total: 41,
        ...overrides,
    };
}

export function makeEnrolment(overrides: Partial<EnrolmentRecord> = {}): EnrolmentRecord {
    return {
        id: 3,
        user: { id: 41, name: 'Priya Nair' },
        course: { id: 1, title: 'Foundations of Web Accessibility', slug: 'web-accessibility' },
        status: 'active',
        progress_percent: 83,
        enrolled_at: '2026-07-01 10:00:00',
        completed_at: null,
        last_activity_at: '2026-09-02 08:00:00',
        entitlement: {
            id: 11,
            user_id: 41,
            course_id: 1,
            source: 'purchase',
            source_ref: 'order-9',
            status: 'active',
            granted_by: null,
            starts_at: '2026-07-01 10:00:00',
            expires_at: null,
        },
        ...overrides,
    };
}

export function makeSuspendedEnrolment(): EnrolmentRecord {
    return makeEnrolment({
        id: 4,
        user: { id: 42, name: 'Tom Weiss' },
        status: 'suspended',
        progress_percent: 41,
        last_activity_at: '2026-08-10 12:00:00',
        entitlement: {
            id: 12,
            user_id: 42,
            course_id: 1,
            source: 'manual',
            source_ref: null,
            status: 'expired',
            granted_by: 7,
            starts_at: '2026-02-14 09:00:00',
            expires_at: '2026-08-14 09:00:00',
        },
    });
}
