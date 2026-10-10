import { describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { Curriculum, LessonView, MyProgress } from '@/shared/api';
import { joomlaStaleCsrfToken, noEnrolments, wordpressStaleNonce } from '@/shared/testing/host-session-answers';
import { PlayerApp } from './app';
import {
    courseFixture,
    jsonResponse,
    makeConfig,
    makeCurriculum,
    makeHarness,
    makeLessonView,
    makeMyProgress,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';

/*
 * The whole player against the SDK boundary: deep-link boot, the resume
 * rule, loading, error with retry, not-enrolled, suspended, the completion
 * celebration, and the rail updating when the server marks a lesson
 * complete after a download heartbeat.
 */

interface AppState {
    curriculum: Curriculum;
    progress: MyProgress;
    lessons: Record<number, LessonView>;
}

interface AppWorld {
    purchaseUrl?: string;
    curriculum?: Curriculum;
    progress?: MyProgress;
    lessons?: Record<number, LessonView>;
    curriculumFails?: { remaining: number };
    /** Lesson GETs answer 401: the host token expired mid-session. */
    lessonAuthExpired?: boolean;
    /** Lesson id to the status the server refuses it with. */
    refusedLessons?: Record<number, number>;
    /**
     * The host's own answer to a stale credential. Once the boot has
     * succeeded, every lesson read and the session check get it, because
     * both hosts refuse a stale credential on every path.
     */
    staleCredential?: () => Response;
    /** Runs on every heartbeat POST; mutate the state to play the server. */
    onProgressPost?: (state: AppState) => void;
}

function renderApp(world: AppWorld = {}, configOverrides: Parameters<typeof makeConfig>[0] = {}) {
    const state: AppState = {
        curriculum: world.curriculum ?? makeCurriculum(),
        progress: world.progress ?? makeMyProgress(),
        lessons: world.lessons ?? defaultLessons(),
    };
    const config = makeConfig(configOverrides);
    const harness = makeHarness((request: RecordedRequest) => {
        if (request.method === 'GET' && request.path === '/v1/courses/web-accessibility') {
            return jsonResponse({ ...courseFixture, purchase_url: world.purchaseUrl ?? courseFixture.purchase_url });
        }
        if (request.method === 'GET' && request.path === '/v1/courses/1/curriculum') {
            if (world.curriculumFails !== undefined && world.curriculumFails.remaining > 0) {
                world.curriculumFails.remaining -= 1;
                return problemResponse(502, 'Bad gateway');
            }
            return jsonResponse(state.curriculum);
        }
        if (request.method === 'GET' && request.path === '/v1/me/progress?course_id=1') {
            return jsonResponse(state.progress);
        }
        if (request.method === 'GET' && request.path.startsWith('/v1/me/certificates?')) {
            return jsonResponse({
                items: [
                    {
                        id: 1,
                        course_id: 1,
                        enrolment_id: 3,
                        code: 'NL-7QK2-9M4X',
                        issued_at: '2026-09-01 10:00:00',
                        expires_at: null,
                        revoked_at: null,
                    },
                ],
            });
        }
        if (request.method === 'GET' && request.path === '/v1/me/enrolments?per_page=1') {
            /* The session check the tracker asks after a 403. */
            return world.staleCredential?.() ?? noEnrolments();
        }
        const lessonMatch = request.path.match(/^\/v1\/lessons\/(\d+)$/);
        if (request.method === 'GET' && lessonMatch !== null) {
            if (world.lessonAuthExpired === true) {
                return problemResponse(401, 'Unauthorized');
            }
            if (world.staleCredential !== undefined) {
                return world.staleCredential();
            }
            const refusal = world.refusedLessons?.[Number(lessonMatch[1])];
            if (refusal !== undefined) {
                return problemResponse(refusal, refusal === 401 ? 'Unauthorized' : 'Forbidden');
            }
            const lesson = state.lessons[Number(lessonMatch[1])];
            return lesson !== undefined ? jsonResponse(lesson) : null;
        }
        const beatMatch = request.path.match(/^\/v1\/lessons\/(\d+)\/progress$/);
        if (request.method === 'POST' && beatMatch !== null) {
            world.onProgressPost?.(state);
            return new Response(null, { status: 204 });
        }
        return null;
    }, config);
    const view = render(<PlayerApp />, { wrapper: harness.wrapper });
    return { ...harness, view, state };
}

function defaultLessons(): Record<number, LessonView> {
    return {
        4: makeLessonView({
            id: 4,
            title: 'Captions and transcripts',
            type: 'video',
            duration_seconds: 483,
            content: { provider: 'youtube', url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' },
        }),
        5: makeLessonView({
            id: 5,
            title: 'Colour and contrast checklist',
            type: 'resource',
            content: {
                files: [
                    {
                        name: 'contrast-checklist-v3.pdf',
                        size_bytes: 421888,
                        url: 'https://files.example.test/signed/checklist.pdf?sig=abc',
                    },
                ],
            },
        }),
        8: makeLessonView({ id: 8, title: 'Focus management', type: 'reading', content: { body: '<p>Body</p>' } }),
    };
}

describe('PlayerApp boot', () => {
    it('shows the shape-true skeleton while loading', () => {
        renderApp();
        expect(screen.getByTestId('player-skeleton')).toBeInTheDocument();
    });

    it('resumes at the first in-progress lesson and renders the artboard chrome', async () => {
        renderApp();
        await screen.findByRole('heading', { name: 'Captions and transcripts' });
        /* Header: title, counts and completion rule in one strip. */
        expect(screen.getByRole('heading', { name: 'Foundations of Web Accessibility' })).toBeInTheDocument();
        expect(
            screen.getByText('2 of 8 lessons complete'),
        ).toBeInTheDocument();
        /* The rail highlights the open lesson. */
        const rail = screen.getByRole('navigation', { name: 'Course curriculum' });
        expect(within(rail).getByRole('button', { current: true })).toHaveTextContent('Captions and transcripts');
        /* Course progress ring reads the summary percent. */
        expect(screen.getByRole('progressbar', { name: 'Course progress' })).toHaveAttribute('aria-valuenow', '25');
    });

    it('honours a lesson deep link from the mount config', async () => {
        renderApp({}, { lessonId: 8 });
        await screen.findByRole('heading', { name: 'Focus management' });
        const rail = screen.getByRole('navigation', { name: 'Course curriculum' });
        expect(within(rail).getByRole('button', { current: true })).toHaveTextContent('Focus management');
    });

    it('toggles focus mode without replacing the open lesson or refetching it', async () => {
        const harness = renderApp({}, { lessonId: 8 });
        const heading = await screen.findByRole('heading', { name: 'Focus management' });
        const reads = () => harness.requests.filter((request) => request.method === 'GET' && request.path === '/v1/lessons/8').length;
        const initialReads = reads();
        await userEvent.click(screen.getByRole('button', { name: 'Focus mode' }));
        expect(screen.queryByRole('navigation', { name: 'Course curriculum' })).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Focus management' })).toBe(heading);
        await userEvent.click(screen.getByRole('button', { name: 'Show curriculum' }));
        expect(within(screen.getByRole('navigation', { name: 'Course curriculum' })).getByRole('button', { current: true }))
            .toHaveTextContent('Focus management');
        expect(reads()).toBe(initialReads);
    });

    it('recovers from a failed boot through Try again, naming the status the artboard way', async () => {
        renderApp({ curriculumFails: { remaining: 1 } });
        await screen.findByText("This course couldn't load");
        expect(screen.getByText(/The server didn't respond \(error 502\)/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back to my learning' })).toHaveAttribute('href', '/my-learning');
        await userEvent.click(screen.getByRole('button', { name: 'Try again' }));
        await screen.findByRole('heading', { name: 'Captions and transcripts' });
    });
});

describe('PlayerApp access states', () => {
    it('shows the not-enrolled state when the curriculum has no enrolment', async () => {
        renderApp({
            curriculum: makeCurriculum({ enrolment: null }),
            progress: makeMyProgress(),
        });
        await screen.findByText("You're not enrolled in this course");
        expect(screen.getByRole('link', { name: 'View course page' })).toHaveAttribute(
            'href',
            '/course/web-accessibility',
        );
    });

    it('opens a free-preview lesson without an enrolment and never starts progress tracking', async () => {
        const base = makeCurriculum();
        const curriculum: Curriculum = {
            ...base,
            enrolment: null,
            modules: base.modules.map((module) => ({
                ...module,
                lessons: module.lessons.map((lesson) => ({
                    ...lesson,
                    free_preview: lesson.id === 8,
                    progress: null,
                })),
            })),
        };
        const harness = renderApp(
            {
                curriculum,
                lessons: {
                    ...defaultLessons(),
                    8: makeLessonView({
                        id: 8,
                        title: 'Focus management',
                        type: 'reading',
                        free_preview: true,
                        content: { body: '<p>Preview body</p>' },
                    }),
                },
            },
            { user: { id: 42, name: 'Course author' } },
        );

        await screen.findByRole('heading', { name: 'Focus management' });
        expect(screen.getByRole('heading', { name: 'Foundations of Web Accessibility' })).toBeInTheDocument();
        expect(screen.queryByText("You're not enrolled in this course")).not.toBeInTheDocument();
        expect(harness.requests.some((request) => request.path === '/v1/me/progress?course_id=1')).toBe(false);
        expect(harness.requests.some((request) => request.method === 'POST')).toBe(false);
    });

    /*
     * The guest's path through a free preview: the preview lesson opens, and
     * a lesson outside it answers 401. That is a permission answer, so the
     * pane shows the permission card and the rail stays, letting the guest
     * go back to what the course does expose; it is neither an error with a
     * retry nor the expired-session screen, because a guest has no session.
     */
    it('shows a guest the permission card for a lesson outside the free preview and keeps the rail', async () => {
        const base = makeCurriculum();
        const curriculum: Curriculum = {
            ...base,
            enrolment: null,
            modules: base.modules.map((module) => ({
                ...module,
                lessons: module.lessons.map((lesson) => ({ ...lesson, free_preview: lesson.id === 8, progress: null })),
            })),
        };
        renderApp(
            {
                curriculum,
                lessons: {
                    ...defaultLessons(),
                    8: makeLessonView({ id: 8, title: 'Focus management', type: 'reading', free_preview: true, content: { body: '<p>Preview body</p>' } }),
                },
                refusedLessons: { 4: 401 },
            },
            { user: { id: 0, name: '' }, lessonId: 4 },
        );

        expect(await screen.findByText("This lesson isn't available to you.")).toBeInTheDocument();
        expect(screen.getByText(/Sign in to continue\./)).toBeInTheDocument();
        expect(screen.queryByText("This lesson couldn't load")).not.toBeInTheDocument();
        expect(screen.queryByText('Your session has expired.')).not.toBeInTheDocument();

        const rail = screen.getAllByRole('navigation', { name: 'Course curriculum' })[0]!;
        await userEvent.click(within(rail).getByRole('button', { name: /Focus management/ }));
        expect(await screen.findByText('Preview body')).toBeInTheDocument();
    });

    it.each(['https://shop.example/easycommerce/course', 'https://shop.example/product/course'])('renews in the host shop with progress kept: %s', async (purchaseUrl) => {
        renderApp({
            purchaseUrl,
            curriculum: makeCurriculum({ enrolment: { id: 3, status: 'suspended', progress_percent: 58 } }),
            progress: makeMyProgress({
                enrolment_status: 'suspended',
                summary: {
                    lessons_total: 12,
                    lessons_completed: 7,
                    percent: 58,
                    quiz_average: null,
                    final_quiz_passed: null,
                    computed_at: '2026-09-01 10:00:00',
                },
            }),
        });
        await screen.findByTestId('suspended-state');
        expect(screen.getByText('Your access has ended')).toBeInTheDocument();
        expect(screen.getByText(/7 of 12 lessons/)).toBeInTheDocument();
        expect(screen.getByText(/picks up exactly here when access is renewed/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Renew access' })).toHaveAttribute('href', purchaseUrl);
        expect(screen.getByRole('progressbar', { name: 'Course progress' })).toHaveAttribute('aria-valuenow', '58');
    });

    it('renders the session-expired state when the token dies mid-session, never offline or enrolment copy', async () => {
        /* Boot succeeds (session established), then every lesson request
           answers 401: the host nonce or token expired under the app. */
        renderApp({ lessonAuthExpired: true });
        await screen.findByTestId('session-expired');
        expect(screen.getByText('Your session has expired.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Reload and sign in again' })).toBeInTheDocument();
        expect(screen.queryByText("You're not enrolled in this course")).not.toBeInTheDocument();
        expect(screen.queryByText("You're offline.")).not.toBeInTheDocument();
    });

    it('celebrates a completed course with the certificate, and Review lessons returns to the player', async () => {
        renderApp({
            curriculum: makeCurriculum({ enrolment: { id: 3, status: 'completed', progress_percent: 100 } }),
            progress: makeMyProgress({
                enrolment_status: 'completed',
                summary: {
                    lessons_total: 8,
                    lessons_completed: 8,
                    percent: 100,
                    quiz_average: null,
                    final_quiz_passed: null,
                    computed_at: '2026-09-01 10:00:00',
                },
            }),
        });
        await screen.findByTestId('course-complete');
        expect(screen.getByText('Course complete')).toBeInTheDocument();
        expect(screen.getByText('All 8 lessons done')).toBeInTheDocument();
        await screen.findByText(/NL-7QK2-9M4X/);
        await userEvent.click(screen.getByRole('button', { name: 'Review lessons' }));
        await screen.findByRole('navigation', { name: 'Course curriculum' });
    });
});

/** The fixture outline with no enrolment and exactly one lesson its author exposed as a free preview. */
function freePreviewCurriculum(previewLessonId: number): Curriculum {
    const base = makeCurriculum({ enrolment: null });
    return {
        ...base,
        modules: base.modules.map((module) => ({
            ...module,
            lessons: module.lessons.map((lesson) => ({ ...lesson, free_preview: lesson.id === previewLessonId, progress: null })),
        })),
    };
}

describe('a refusal under a signed-in mount', () => {
    /*
     * A signed-in visitor without an enrolment, in a free preview, opens a
     * lesson outside it. The core knows who is asking, so it refuses with 403
     * rather than 401. The session still stands, so the answer belongs to the
     * lesson pane and the player stays; reading it as an expired host token
     * replaced the player with a sign-in-again screen that reloads into the
     * same refusal.
     */
    it('keeps the player and answers in the lesson pane when the core refuses a lesson outside the preview', async () => {
        const harness = renderApp(
            { curriculum: freePreviewCurriculum(8), refusedLessons: { 5: 403 } },
            { user: { id: 42, name: 'Priya Nair' }, lessonId: 5 },
        );

        expect(await screen.findByText("This lesson isn't available to you.")).toBeInTheDocument();
        expect(screen.getByText(/It belongs to a course you're not enrolled in\./)).toBeInTheDocument();
        expect(screen.queryByTestId('session-expired')).not.toBeInTheDocument();
        expect(screen.queryByText('Your session has expired.')).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Foundations of Web Accessibility' })).toBeInTheDocument();
        const rail = screen.getByRole('navigation', { name: 'Course curriculum' });
        expect(within(rail).getByRole('button', { current: true })).toHaveTextContent('Colour and contrast checklist');
        /* The tracker asked the host once whether the session still stands. */
        expect(harness.requests.filter((request) => request.path === '/v1/me/enrolments?per_page=1')).toHaveLength(1);
    });

    it.each([
        ['Joomla, stale X-CSRF-Token', joomlaStaleCsrfToken],
        ['WordPress, stale nonce', wordpressStaleNonce],
    ])('still shows the expired session when the host refuses the session check too: %s', async (_host, staleCredential) => {
        renderApp({ staleCredential }, { lessonId: 5 });

        await screen.findByTestId('session-expired');
        expect(screen.getByText('Your session has expired.')).toBeInTheDocument();
        expect(screen.queryByText("This lesson isn't available to you.")).not.toBeInTheDocument();
        expect(screen.queryByText("You're not enrolled in this course")).not.toBeInTheDocument();
    });
});

describe('assignment awaiting-grade in the rail', () => {
    it('shows the dashed awaiting-grade state for a submitted, ungraded assignment', async () => {
        renderApp(
            {
                lessons: {
                    ...defaultLessons(),
                    9: makeLessonView({
                        id: 9,
                        title: 'Accessibility audit exercise',
                        type: 'assignment',
                        module_id: 3,
                        content: {
                            instructions: '<p>Audit a page.</p>',
                            submission: { status: 'submitted' },
                        },
                    }),
                },
            },
            { lessonId: 9 },
        );
        await screen.findByRole('heading', { name: 'Accessibility audit exercise' });
        const rail = screen.getByRole('navigation', { name: 'Course curriculum' });
        const row = within(rail).getByRole('button', { name: /Accessibility audit exercise/ });
        await waitFor(() => {
            expect(row.querySelector('[data-state="awaiting_grade"]')).not.toBeNull();
        });
        expect(row).toHaveTextContent('Awaiting grade');
    });
});

describe('lesson completion transition', () => {
    it('updates the rail when the server marks a lesson complete after the download heartbeat', async () => {
        renderApp(
            {
                onProgressPost: (state) => {
                    /* The server recorded the completion: subsequent
                       curriculum reads answer with the lesson completed. */
                    state.curriculum = markCompleted(state.curriculum, 5);
                    state.lessons[5] = {
                        ...state.lessons[5]!,
                        progress: state.curriculum.modules.flatMap((module) => module.lessons)
                            .find((lesson) => lesson.id === 5)!.progress,
                    };
                },
            },
            { lessonId: 5 },
        );
        await screen.findByRole('heading', { name: 'Colour and contrast checklist' });

        const rail = screen.getByRole('navigation', { name: 'Course curriculum' });
        const row = () => within(rail).getByRole('button', { name: /Colour and contrast checklist/ });
        expect(row().querySelector('[data-state="completed"]')).toBeNull();

        const link = screen.getByRole('link', { name: /Download/ });
        link.addEventListener('click', (event) => event.preventDefault());
        await userEvent.click(link);

        /* The heartbeat carried the claim, the server confirmed, the rail
           refetched and now shows the completed state. */
        await waitFor(() => {
            expect(row().querySelector('[data-state="completed"]')).not.toBeNull();
        });
        await screen.findByText('Downloaded ✓');
    });
});

function markCompleted(curriculum: Curriculum, lessonId: number): Curriculum {
    return {
        ...curriculum,
        modules: curriculum.modules.map((module) => ({
            ...module,
            lessons: module.lessons.map((lesson) =>
                lesson.id === lessonId
                    ? {
                          ...lesson,
                          progress: {
                              lesson_id: lessonId,
                              status: 'completed',
                              progress_percent: 100,
                              seconds_spent: 60,
                              position_seconds: null,
                              first_accessed_at: '2026-09-01 10:00:00',
                              completed_at: '2026-09-01 10:01:00',
                              last_heartbeat_at: '2026-09-01 10:01:00',
                          },
                      }
                    : lesson,
            ),
        })),
    };
}
