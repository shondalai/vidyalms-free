import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ManagedCourse } from '@vidyalms/api-sdk';
import { CoursesScreen } from './courses';
import {
    jsonResponse,
    makeConfig,
    makeCourse,
    makeHarness,
    problemResponse,
    type RecordedRequest,
} from '../testing/harness';

/*
 * Artboard 2a end to end against the SDK boundary: the columns, the chips
 * and their counts, server-side search, sort and paging, the workbench
 * link, the fresh-install welcome and the New course dialog.
 */

const COURSES: ManagedCourse[] = [
    makeCourse({ id: 1, title: 'Foundations of Web Accessibility' }),
    makeCourse({
        id: 2,
        title: 'Inclusive Design Patterns',
        completion_rule: 'pass_final',
        lesson_count: 18,
        enrolled_count: 95,
        completion_rate: 31,
        instructors: [{ user_id: 8, name: 'M. Chen', role: 'lead' }],
    }),
    makeCourse({
        id: 3,
        title: 'Advanced Screen Reader Testing',
        state: 'draft',
        completion_rule: 'percentage',
        completion_threshold: 80,
        lesson_count: 9,
        enrolled_count: 0,
        completion_rate: null,
        published_at: null,
    }),
];

interface World {
    courses?: ManagedCourse[];
    counts?: Record<string, number>;
    listStatus?: number;
    createStatus?: number;
    /** Overrides the reported total, so paging has more than one page. */
    total?: number;
    /** Holds this state-filtered list response until the test releases it. */
    deferredState?: ManagedCourse['state'];
}

function deferredJsonResponse(payload: unknown): { response: Response; release: () => void } {
    let release = () => undefined;
    const body = new ReadableStream<Uint8Array>({
        start(controller) {
            release = () => {
                controller.enqueue(new TextEncoder().encode(JSON.stringify(payload)));
                controller.close();
            };
        },
    });
    return {
        response: new Response(body, { headers: { 'Content-Type': 'application/json' } }),
        release,
    };
}

function renderScreen(world: World = {}) {
    const courses = world.courses ?? COURSES;
    const counts = world.counts ?? { published: 14, draft: 3, archived: 0 };
    const config = makeConfig({ screen: 'courses' });
    let releaseDeferred: (() => void) | undefined;
    const harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        if (request.method === 'GET' && url.pathname === '/v1/manage/courses') {
            if (world.listStatus !== undefined) {
                return problemResponse(world.listStatus, 'Refused');
            }
            const state = url.searchParams.get('state');
            if (url.searchParams.get('per_page') === '1') {
                return jsonResponse({ items: [], total: counts[state ?? ''] ?? 0, page: 1, per_page: 1 });
            }
            const query = (url.searchParams.get('q') ?? '').toLowerCase();
            const items = courses
                .filter((course) => state === null || course.state === state)
                .filter((course) => query === '' || course.title.toLowerCase().includes(query));
            const payload = {
                items,
                total: world.total ?? items.length,
                page: Number(url.searchParams.get('page') ?? 1),
                per_page: 20,
            };
            if (state === world.deferredState && releaseDeferred === undefined) {
                const deferred = deferredJsonResponse(payload);
                releaseDeferred = deferred.release;
                return deferred.response;
            }
            return jsonResponse(payload);
        }
        if (request.method === 'POST' && url.pathname === '/v1/manage/courses') {
            if (world.createStatus !== undefined) {
                return problemResponse(world.createStatus, 'Refused');
            }
            const body = request.body as { title: string };
            return jsonResponse({
                ...makeCourse({ id: 42, title: body.title, state: 'draft' }),
                description: null,
                created_by: 1,
                created_at: '2026-09-01 10:00:00',
                prerequisites: [],
                pending_grading_count: 0,
            });
        }
        return null;
    }, config);
    const view = render(<CoursesScreen />, { wrapper: harness.wrapper });
    return { ...harness, view, releaseDeferred: () => releaseDeferred?.() };
}

/** The last list request the screen made, counts and dialog reads aside. */
function lastListRequest(requests: RecordedRequest[]): URL {
    const listing = requests.filter(
        (request) =>
            request.method === 'GET' &&
            request.path.startsWith('/v1/manage/courses') &&
            !request.path.includes('per_page=1&'),
    );
    return new URL(listing[listing.length - 1]?.path ?? '', 'http://localhost');
}

beforeEach(() => {
    window.location.hash = '';
    localStorage.removeItem('vidyalms-course-view');
});

describe('CoursesScreen', () => {
    it('renders a row per course with state, enrolled and completion rate', async () => {
        renderScreen();
        expect(await screen.findByText('Foundations of Web Accessibility')).toBeInTheDocument();
        const table = screen.getByRole('table', { name: 'Courses' });
        expect(within(table).getAllByRole('row')).toHaveLength(4);
        expect(within(table).getByText('R. Okafor · 12 lessons · all lessons rule')).toBeInTheDocument();
        expect(within(table).getByText('M. Chen · 18 lessons · pass final quiz')).toBeInTheDocument();
        expect(within(table).getByText('R. Okafor · 9 lessons · 80% threshold')).toBeInTheDocument();
        expect(within(table).getAllByText('Published')).toHaveLength(2);
        expect(within(table).getByText('Draft')).toBeInTheDocument();
        expect(within(table).getByText('24%')).toBeInTheDocument();
    });

    it('shows the state counts on the chips', async () => {
        renderScreen();
        expect(await screen.findByRole('button', { name: 'Published (14)' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Draft (3)' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Archived (0)' })).toBeInTheDocument();
    });

    it('filters on the server when a chip is chosen', async () => {
        const { requests } = renderScreen();
        await screen.findByRole('button', { name: 'Draft (3)' });
        await userEvent.click(screen.getByRole('button', { name: 'Draft (3)' }));
        await waitFor(() => expect(lastListRequest(requests).searchParams.get('state')).toBe('draft'));
        expect(await screen.findByText('Advanced Screen Reader Testing')).toBeInTheDocument();
    });

    it('searches on the server, debounced', async () => {
        const { requests } = renderScreen();
        await screen.findByRole('table', { name: 'Courses' });
        await userEvent.type(screen.getByRole('searchbox', { name: 'Search' }), 'inclusive');
        await waitFor(() => expect(lastListRequest(requests).searchParams.get('q')).toBe('inclusive'));
    });

    it('sorts on the server from the column header', async () => {
        const { requests } = renderScreen();
        await screen.findByRole('table', { name: 'Courses' });
        await userEvent.click(screen.getByRole('button', { name: 'Enrolled' }));
        await waitFor(() => {
            const url = lastListRequest(requests);
            expect(url.searchParams.get('sort')).toBe('enrolled_count');
            expect(url.searchParams.get('order')).toBe('asc');
        });
        await userEvent.click(screen.getByRole('button', { name: 'Enrolled' }));
        await waitFor(() => expect(lastListRequest(requests).searchParams.get('order')).toBe('desc'));
    });

    it('opens a course at the host workbench URL', async () => {
        renderScreen();
        const open = await screen.findByRole('link', { name: 'Open Foundations of Web Accessibility' });
        expect(open).toHaveAttribute('href', '/admin/courses#/course/1');
    });

    it('clears combined filters and returns to page one while retaining the chosen sort', async () => {
        const { requests } = renderScreen({ total: 45 });
        await userEvent.click(await screen.findByRole('button', { name: 'Draft (3)' }));
        await userEvent.type(screen.getByRole('searchbox', { name: 'Search' }), 'advanced');
        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Sort courses' }), 'updated_at');
        await userEvent.click(screen.getByRole('button', { name: 'Page 2' }));
        await waitFor(() => expect(lastListRequest(requests).searchParams.get('page')).toBe('2'));
        await userEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        await waitFor(() => {
            const query = lastListRequest(requests).searchParams;
            expect(query.get('state')).toBeNull();
            expect(query.get('q')).toBeNull();
            expect(query.get('page')).toBe('1');
            expect(query.get('sort')).toBe('updated_at');
        });
        expect(screen.getByRole('searchbox', { name: 'Search' })).toHaveValue('');
        expect(screen.queryByRole('button', { name: 'Clear filters' })).toBeNull();
    });

    it('keeps filters and course actions when switching to cards and back', async () => {
        renderScreen();
        await userEvent.click(await screen.findByRole('button', { name: 'Draft (3)' }));
        await userEvent.click(screen.getByRole('button', { name: 'Course card view' }));
        const cards = await screen.findByRole('list', { name: 'Courses' });
        await waitFor(() => expect(within(cards).getAllByRole('listitem')).toHaveLength(1));
        expect(within(cards).getByRole('link', { name: 'Open Advanced Screen Reader Testing' })).toHaveAttribute('href', '/admin/courses#/course/3');
        expect(within(cards).getByRole('button', { name: 'Publish Advanced Screen Reader Testing' })).toBeInTheDocument();
        expect(within(cards).getByRole('button', { name: 'Delete Advanced Screen Reader Testing' })).toBeInTheDocument();
        expect(localStorage.getItem('vidyalms-course-view')).toBe('grid');
        await userEvent.click(screen.getByRole('button', { name: 'Course list view' }));
        expect(await screen.findByRole('table', { name: 'Courses' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Draft (3)' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('welcomes a fresh install where no course exists at all', async () => {
        renderScreen({ courses: [], counts: { published: 0, draft: 0, archived: 0 } });
        expect(await screen.findByText('Welcome to VidyaLMS')).toBeInTheDocument();
    });

    it('says a filtered list is empty rather than welcoming again', async () => {
        renderScreen({ courses: [] });
        await userEvent.click(await screen.findByRole('button', { name: 'Draft (3)' }));
        expect(await screen.findByText('No courses match these filters.')).toBeInTheDocument();
        expect(screen.queryByText('Welcome to VidyaLMS')).toBeNull();
    });

    it('refuses to create a course without a title', async () => {
        const { requests } = renderScreen();
        await screen.findByRole('table', { name: 'Courses' });
        await userEvent.click(screen.getByRole('button', { name: 'New course' }));
        await userEvent.click(screen.getByRole('button', { name: 'Create draft' }));
        expect(await screen.findByText('Enter a title.')).toBeInTheDocument();
        expect(requests.some((request) => request.method === 'POST')).toBe(false);
    });

    it('creates a draft and offers it in the confirmation', async () => {
        const { requests } = renderScreen();
        await screen.findByRole('table', { name: 'Courses' });
        await userEvent.click(screen.getByRole('button', { name: 'New course' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Title' }), 'Accessible Forms');
        await userEvent.click(screen.getByRole('button', { name: 'Create draft' }));

        expect(await screen.findByText('Draft created: Accessible Forms.')).toBeInTheDocument();
        const created = requests.find((request) => request.method === 'POST');
        expect(created?.body).toEqual({
            title: 'Accessible Forms',
            category_id: null,
            image: null,
            completion_rule: 'all_lessons',
            completion_threshold: null,
        });
        expect(screen.getByRole('link', { name: 'Open' })).toHaveAttribute('href', '/admin/courses#/course/42');
    });

    it('asks for a threshold only under the percentage rule', async () => {
        renderScreen();
        await screen.findByRole('table', { name: 'Courses' });
        await userEvent.click(screen.getByRole('button', { name: 'New course' }));
        expect(screen.queryByRole('spinbutton', { name: 'Threshold (%)' })).toBeNull();
        await userEvent.selectOptions(
            screen.getByRole('combobox', { name: 'Completion rule' }),
            'percentage',
        );
        expect(screen.getByRole('spinbutton', { name: 'Threshold (%)' })).toBeInTheDocument();
    });

    it('pages on the server and reports the rows on screen', async () => {
        const { requests } = renderScreen({ total: 45 });
        expect(await screen.findByText('1 to 3 of 45')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Page 2' }));
        await waitFor(() => expect(lastListRequest(requests).searchParams.get('page')).toBe('2'));
    });

    it('returns to the first page when the filter changes', async () => {
        const { requests } = renderScreen({ total: 45 });
        await userEvent.click(await screen.findByRole('button', { name: 'Page 2' }));
        await waitFor(() => expect(lastListRequest(requests).searchParams.get('page')).toBe('2'));
        await userEvent.click(screen.getByRole('button', { name: 'Draft (3)' }));
        await waitFor(() => expect(lastListRequest(requests).searchParams.get('page')).toBe('1'));
    });

    it('returns to the first page when the title search changes', async () => {
        const { requests } = renderScreen({ total: 45 });
        await userEvent.click(await screen.findByRole('button', { name: 'Page 2' }));
        await waitFor(() => expect(lastListRequest(requests).searchParams.get('page')).toBe('2'));
        await userEvent.type(screen.getByRole('searchbox', { name: 'Search' }), 'inclusive');
        await waitFor(() => {
            const url = lastListRequest(requests);
            expect(url.searchParams.get('q')).toBe('inclusive');
            expect(url.searchParams.get('page')).toBe('1');
        });
    });

    it('keeps the current rows visible and marks the list busy while a new filter loads', async () => {
        const { view, releaseDeferred } = renderScreen({ deferredState: 'draft' });
        expect(await screen.findByText('Foundations of Web Accessibility')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Draft (3)' }));
        await waitFor(() => {
            expect(view.container.querySelector('[aria-busy="true"]')).not.toBeNull();
            expect(screen.getByText('Foundations of Web Accessibility')).toBeInTheDocument();
        });

        releaseDeferred();
        expect(await screen.findByText('Advanced Screen Reader Testing')).toBeInTheDocument();
        await waitFor(() => expect(view.container.querySelector('[aria-busy="true"]')).toBeNull());
    });

    it('makes a list failure retryable', async () => {
        const { requests } = renderScreen({ listStatus: 503 });
        const claim = await screen.findByText("Couldn't load.");
        const failure = claim.closest('[role="alert"]');
        expect(failure).not.toBeNull();

        const before = requests.filter(
            (request) => request.method === 'GET' && !request.path.includes('per_page=1&'),
        ).length;
        await userEvent.click(within(failure as HTMLElement).getByRole('button', { name: 'Try again' }));
        await waitFor(() =>
            expect(
                requests.filter(
                    (request) => request.method === 'GET' && !request.path.includes('per_page=1&'),
                ).length,
            ).toBe(before + 1),
        );
    });

    it('shows the signed-out state when the course list returns 401', async () => {
        renderScreen({ listStatus: 401 });
        expect(await screen.findByText('Sign in to continue.')).toBeInTheDocument();
        expect(screen.getByText('This page needs an administrator session.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
    });

    it('states plainly when the account may not use the management surface', async () => {
        renderScreen({ listStatus: 403 });
        expect(await screen.findByText('No VidyaLMS permissions.')).toBeInTheDocument();
    });
});
