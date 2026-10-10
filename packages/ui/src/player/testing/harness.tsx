import type { ReactNode } from 'react';
import { MOUNT_CONTRACT } from '@/shared/config';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createVidyaLmsClient, type FetchLike } from '@vidyalms/api-sdk';
import { ApiClientContext, MountConfigContext } from '@/shared/context';
import { hostSessionProbe, SessionTracker, SessionTrackerContext, withSessionTracking } from '@/shared/session';
import type { MountConfig } from '@/shared/config';
import type { CourseDetail, Curriculum, LessonView, MyProgress } from '@/shared/api';

/*
 * Test-only harness of the player suites: mount config, an SDK client over
 * a stubbed fetch (the SDK boundary is the mock seam; no network), and the
 * fixtures shaped exactly like the live contract answers.
 */

export function makeConfig(overrides: Partial<MountConfig> = {}): MountConfig {
    return {
        /* Absolute base: Node's Request (the jsdom suites) rejects relative URLs. */
        contract: MOUNT_CONTRACT,
        api: { base: 'http://localhost/v1', mode: 'prefix', headers: { 'X-CSRF-Token': 'test' } },
        course: { id: 1, slug: 'web-accessibility' },
        lessonId: null,
        user: { id: 42, name: 'Priya Nair' },
        locale: 'en-GB',
    idleTimeoutSeconds: 300,
        urls: { course: '/course/web-accessibility', myLearning: '/my-learning' },
        ...overrides,
    };
}

export interface RecordedRequest {
    method: string;
    path: string;
    body: unknown;
}

export type RouteHandler = (request: RecordedRequest) => Response | null;

export interface Harness {
    wrapper: (props: { children: ReactNode }) => ReactNode;
    requests: RecordedRequest[];
    queryClient: QueryClient;
    config: MountConfig;
    sessionTracker: SessionTracker;
}

/**
 * Routes requests by "METHOD pathname" through the handler; a null answer
 * falls through to 404. Bodies are recorded parsed for assertions.
 */
export function makeHarness(handler: RouteHandler, config: MountConfig = makeConfig()): Harness {
    const requests: RecordedRequest[] = [];
    const fetchLike: FetchLike = async (request) => {
        const url = new URL(request.url, 'http://localhost/');
        const raw = request.method === 'GET' || request.method === 'HEAD' ? null : await request.text();
        const recorded: RecordedRequest = {
            method: request.method,
            path: url.pathname + url.search,
            body: raw !== null && raw !== '' ? JSON.parse(raw) : null,
        };
        requests.push(recorded);
        const response = handler(recorded);
        if (response !== null) {
            return response;
        }
        return new Response(
            JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }),
            { status: 404, headers: { 'Content-Type': 'application/problem+json' } },
        );
    };
    /* Same wiring as mountApp: every response feeds the session tracker,
       a guest mount has no session to expire, and a 403 is confirmed by the
       session check through the same stubbed host. */
    const sessionTracker = new SessionTracker(config.user.id > 0, hostSessionProbe(config.api, fetchLike));
    const client = createVidyaLmsClient(config.api, withSessionTracking(sessionTracker, fetchLike));
    const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const wrapper = ({ children }: { children: ReactNode }) => (
        <MountConfigContext.Provider value={config}>
            <SessionTrackerContext.Provider value={sessionTracker}>
                <ApiClientContext.Provider value={client}>
                    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
                </ApiClientContext.Provider>
            </SessionTrackerContext.Provider>
        </MountConfigContext.Provider>
    );
    return { wrapper, requests, queryClient, config, sessionTracker };
}

export function problemResponse(status: number, title = 'Problem', detail?: string): Response {
    return new Response(JSON.stringify({ type: 'about:blank', title, status, detail }), {
        status,
        headers: { 'Content-Type': 'application/problem+json' },
    });
}

/* ── Fixtures ───────────────────────────────────────────────────────── */

export const courseFixture: CourseDetail = {
    id: 1,
    title: 'Foundations of Web Accessibility',
    slug: 'web-accessibility',
    summary: null,
    image: null,
    category_id: null,
    level: null,
    language: 'en-GB',
    price_mode: 'free',
    featured: false,
    published_at: '2026-06-01 10:00:00',
    description: null,
    visibility: 'public',
    state: 'published',
    completion_rule: 'all_lessons',
    completion_threshold: null,
    price: null,
    purchase_url: null,
    price_reason: null,
};

type LessonSeed = {
    id: number;
    title: string;
    type: string;
    status?: 'not_started' | 'in_progress' | 'completed';
    duration?: number | null;
    freePreview?: boolean;
};

/**
 * The contract's "no restrictions" access object.
 *
 * It is the shape every outline row and every lesson view carries whether or
 * not a restriction is stored, which is what lets a site adopt availability
 * without the payload changing shape under its clients.
 */
export const OPEN_ACCESS = {
    state: 'available',
    reason: null,
    reason_parameters: {},
    opens_at: null,
    opens_at_timezone: null,
} as const;

function seedLesson(seed: LessonSeed) {
    return {
        id: seed.id,
        title: seed.title,
        slug: `lesson-${seed.id}`,
        type: seed.type,
        summary: null,
        duration_seconds: seed.duration ?? null,
        position: seed.id,
        free_preview: seed.freePreview ?? false,
        access: { ...OPEN_ACCESS },
        progress:
            seed.status === undefined
                ? null
                : {
                      lesson_id: seed.id,
                      status: seed.status,
                      progress_percent: seed.status === 'completed' ? 100 : seed.status === 'in_progress' ? 60 : 0,
                      seconds_spent: 120,
                      position_seconds: null,
                      first_accessed_at: '2026-08-01 10:00:00',
                      completed_at: seed.status === 'completed' ? '2026-08-01 10:30:00' : null,
                      last_heartbeat_at: '2026-08-01 10:30:00',
                  },
    };
}

export function makeCurriculum(overrides: Partial<Curriculum> = {}): Curriculum {
    return {
        course_id: 1,
        enrolment: { id: 3, status: 'active', progress_percent: 25 },
        modules: [
            {
                id: 1,
                title: 'Getting started',
                summary: null,
                position: 1,
                access: { ...OPEN_ACCESS },
                lessons: [
                    seedLesson({ id: 1, title: 'Welcome', type: 'video', status: 'completed', duration: 300 }),
                    seedLesson({ id: 2, title: 'How this course works', type: 'reading', status: 'completed' }),
                ],
            },
            {
                id: 2,
                title: 'Perceivable content',
                summary: null,
                position: 2,
                access: { ...OPEN_ACCESS },
                lessons: [
                    seedLesson({ id: 4, title: 'Captions and transcripts', type: 'video', status: 'in_progress', duration: 483 }),
                    seedLesson({ id: 5, title: 'Colour and contrast checklist', type: 'resource' }),
                    seedLesson({ id: 6, title: 'Module 2 check', type: 'quiz' }),
                ],
            },
            {
                id: 3,
                title: 'Operable interfaces',
                summary: null,
                position: 3,
                access: { ...OPEN_ACCESS },
                lessons: [
                    seedLesson({ id: 8, title: 'Focus management', type: 'reading', duration: 360 }),
                    seedLesson({ id: 9, title: 'Accessibility audit exercise', type: 'assignment' }),
                    seedLesson({ id: 10, title: 'Course review', type: 'reading' }),
                ],
            },
        ],
        ...overrides,
    };
}

export function makeMyProgress(overrides: Partial<MyProgress> = {}): MyProgress {
    return {
        enrolment_id: 3,
        course_id: 1,
        enrolment_status: 'active',
        summary: {
            lessons_total: 8,
            lessons_completed: 2,
            percent: 25,
            quiz_average: null,
            final_quiz_passed: null,
            computed_at: '2026-09-01 10:00:00',
        },
        lessons: [],
        ...overrides,
    };
}

export function makeLessonView(overrides: Partial<LessonView> & { id: number }): LessonView {
    return {
        course_id: 1,
        module_id: 2,
        title: 'Captions and transcripts',
        slug: `lesson-${overrides.id}`,
        type: 'video',
        summary: null,
        duration_seconds: null,
        position: 1,
        free_preview: false,
        access: { ...OPEN_ACCESS },
        content: null,
        content_available: true,
        progress: null,
        quiz: null,
        ...overrides,
    };
}

export function jsonResponse(payload: unknown): Response {
    return Response.json(payload);
}
