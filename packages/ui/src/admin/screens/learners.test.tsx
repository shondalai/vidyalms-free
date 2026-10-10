import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { AdminLearner, LearnerRecord } from '@vidyalms/api-sdk';
import { LearnersScreen } from './learners';
import {
    jsonResponse,
    makeConfig,
    makeEnrolment,
    makeHarness,
    makeLearnerRecord,
    problemResponse,
    type RecordedRequest,
} from '../testing/harness';

function makeDirectoryLearner(id: number, name = `Learner ${id}`): AdminLearner {
    return {
        user_id: id,
        user: { id, name, email: `learner${id}@example.org` },
        course_count: 3,
        active_course_count: 2,
        completed_course_count: 1,
        average_progress: 64,
        last_activity_at: '2026-09-03 10:00:00',
        last_enrolled_at: '2026-07-01 10:00:00',
    };
}

const ROWS = [makeDirectoryLearner(482, 'Tom Weiss'), makeDirectoryLearner(483, 'Mira Sato')];

interface World {
    rows?: AdminLearner[];
    total?: number;
    listStatuses?: number[];
    record?: Partial<LearnerRecord>;
    recordStatus?: number;
}

function renderScreen(world: World = {}) {
    const rows = world.rows ?? ROWS;
    const statuses = [...(world.listStatuses ?? [])];
    const config = makeConfig({ screen: 'learners' });
    const harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        if (request.method === 'GET' && url.pathname === '/v1/admin/learners') {
            const status = statuses.shift();
            if (status !== undefined && status !== 200) {
                return problemResponse(status, status === 403 ? 'Refused' : 'Unavailable');
            }

            const query = (url.searchParams.get('q') ?? '').toLowerCase();
            const filtered = rows.filter((row) => {
                if (query === '') {
                    return true;
                }
                return (
                    row.user?.name.toLowerCase().includes(query) === true ||
                    row.user?.email.toLowerCase().includes(query) === true
                );
            });
            const page = Number(url.searchParams.get('page') ?? '1');
            const perPage = Number(url.searchParams.get('per_page') ?? '20');
            const start = (page - 1) * perPage;
            return jsonResponse({
                items: filtered.slice(start, start + perPage),
                total: query === '' && world.total !== undefined ? world.total : filtered.length,
                page,
                per_page: perPage,
            });
        }
        if (request.method === 'GET' && /^\/v1\/admin\/learners\/\d+$/.test(url.pathname)) {
            if (world.recordStatus !== undefined) {
                return problemResponse(world.recordStatus, 'Not found');
            }
            return jsonResponse(makeLearnerRecord(world.record));
        }
        if (request.method === 'POST' && /^\/v1\/manage\/entitlements\/\d+\/extend$/.test(url.pathname)) {
            return jsonResponse({ entitlement: null, enrolment: null });
        }
        return null;
    }, config);
    const view = render(<LearnersScreen />, { wrapper: harness.wrapper });
    return { ...harness, view };
}

beforeEach(() => {
    window.location.hash = '';
});

describe('LearnersScreen directory', () => {
    it('renders actual learner aggregates from the paged directory route', async () => {
        const { requests } = renderScreen();

        await screen.findByText('Tom Weiss');
        const table = screen.getByRole('table', { name: 'Learner directory' });
        expect(within(table).getByText('Tom Weiss')).toBeInTheDocument();
        expect(within(table).getByText('learner482@example.org')).toBeInTheDocument();
        expect(within(table).getAllByText('3')).toHaveLength(2);
        expect(within(table).getByRole('progressbar', { name: 'Average progress for Tom Weiss' })).toHaveAttribute(
            'aria-valuenow',
            '64',
        );
        expect(requests[0]?.path).toContain('/v1/admin/learners?');
        expect(requests.some((request) => request.path.startsWith('/v1/admin/users'))).toBe(false);
    });

    it('renders only the bounded response page even when the total is ten thousand', async () => {
        renderScreen({
            rows: Array.from({ length: 20 }, (_unused, index) => makeDirectoryLearner(1000 + index)),
            total: 10_000,
        });

        expect(await screen.findByText('10000 total')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: /^Open the record of Learner/ })).toHaveLength(20);
        expect(screen.getByText('1 to 20 of 10000')).toBeInTheDocument();
    });

    it('sends page changes to the server and resets to page one for a search', async () => {
        const rows = Array.from({ length: 25 }, (_unused, index) =>
            makeDirectoryLearner(1000 + index, index === 3 ? 'Mira Match' : `Person ${index + 1}`),
        );
        const { requests } = renderScreen({ rows });

        await userEvent.click(await screen.findByRole('button', { name: /^Next/ }));
        await waitFor(() => {
            const last = new URL(requests.at(-1)?.path ?? '', 'http://localhost');
            expect(last.searchParams.get('page')).toBe('2');
        });

        await userEvent.type(screen.getByRole('searchbox', { name: 'Find a person' }), 'Mira');
        await waitFor(() => {
            const last = new URL(requests.at(-1)?.path ?? '', 'http://localhost');
            expect(last.searchParams.get('page')).toBe('1');
            expect(last.searchParams.get('q')).toBe('Mira');
        });
    });

    it('resets paging and requests the selected server sort', async () => {
        const rows = Array.from({ length: 25 }, (_unused, index) => makeDirectoryLearner(1000 + index));
        const { requests } = renderScreen({ rows });

        await userEvent.click(await screen.findByRole('button', { name: /^Next/ }));
        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Sort learners' }), 'courses');

        await waitFor(() => {
            const last = new URL(requests.at(-1)?.path ?? '', 'http://localhost');
            expect(last.searchParams.get('sort')).toBe('courses');
            expect(last.searchParams.get('order')).toBe('desc');
            expect(last.searchParams.get('page')).toBe('1');
        });
    });

    it('retries a transient failure and then renders the page', async () => {
        renderScreen({ listStatuses: [500, 500, 200] });

        await userEvent.click(await screen.findByRole('button', { name: 'Try again' }, { timeout: 3000 }));
        expect(await screen.findByText('Tom Weiss')).toBeInTheDocument();
    });

    it('renders the decided 401 and 403 states without retrying them', async () => {
        const signedOut = renderScreen({ listStatuses: [401] });
        expect(await screen.findByText('Sign in to continue.')).toBeInTheDocument();
        expect(signedOut.requests).toHaveLength(1);
        signedOut.view.unmount();

        const forbidden = renderScreen({ listStatuses: [403] });
        expect(await screen.findByText('Managers see courses only.')).toBeInTheDocument();
        expect(forbidden.requests).toHaveLength(1);
    });

    it('keeps removed host accounts as truthful history rows without a broken record action', async () => {
        renderScreen({ rows: [{ ...makeDirectoryLearner(900), user: null }] });

        expect(await screen.findByText('Account removed')).toBeInTheDocument();
        expect(screen.getByText('Learning history retained · user #900')).toBeInTheDocument();
        expect(screen.getByText('Record unavailable')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Open the record/ })).toBeNull();
    });

    it('says when no learner matches', async () => {
        window.location.hash = '#/?search=nobody';
        renderScreen({ rows: [] });
        expect(await screen.findByText('No learners match this search.')).toBeInTheDocument();
    });
});

describe('LearnersScreen record route', () => {
    it('opens one record through the preserved hash and shows its complete current contract', async () => {
        renderScreen();
        await userEvent.click(await screen.findByRole('button', { name: 'Open the record of Tom Weiss' }));

        expect(await screen.findByRole('heading', { name: 'Tom Weiss' })).toBeInTheDocument();
        expect(window.location.hash).toBe('#/learner/482');
        expect(screen.getByText('tom.weiss@example.org · user #482')).toBeInTheDocument();
        expect(screen.getByText('41% kept')).toBeInTheDocument();
        expect(screen.getByText('NL-3FA8-P2QM')).toBeInTheDocument();
    });

    it('does not fetch the directory for a directly linked record', async () => {
        window.location.hash = '#/learner/482';
        const { requests } = renderScreen();

        expect(await screen.findByRole('heading', { name: 'Tom Weiss' })).toBeInTheDocument();
        expect(requests.some((request) => request.path.startsWith('/v1/admin/learners?'))).toBe(false);
    });

    it('goes back to the exact server-paged directory', async () => {
        window.location.hash = '#/learner/482';
        renderScreen();
        await screen.findByRole('heading', { name: 'Tom Weiss' });

        await userEvent.click(screen.getByRole('button', { name: 'Back to the search' }));
        expect(window.location.hash).toBe('#/');
        expect(await screen.findByRole('table', { name: 'Learner directory' })).toBeInTheDocument();
    });

    it('round-trips through a record without losing the originating list state', async () => {
        window.location.hash = '#/?search=Person&sort=courses&page=2';
        const rows = Array.from({ length: 25 }, (_unused, index) =>
            makeDirectoryLearner(1000 + index, `Person ${index + 1}`),
        );
        const { requests } = renderScreen({ rows });

        await userEvent.click(
            await screen.findByRole('button', { name: 'Open the record of Person 21' }),
        );
        expect(window.location.hash).toBe('#/learner/1020');
        await screen.findByRole('heading', { name: 'Tom Weiss' });

        await userEvent.click(screen.getByRole('button', { name: 'Back to the search' }));
        expect(window.location.hash).toBe('#/?search=Person&sort=courses&page=2');
        await waitFor(() => {
            const directoryRequest = [...requests]
                .reverse()
                .find((request) => request.path.startsWith('/v1/admin/learners?'));
            const url = new URL(directoryRequest?.path ?? '', 'http://localhost');
            expect(url.searchParams.get('q')).toBe('Person');
            expect(url.searchParams.get('sort')).toBe('courses');
            expect(url.searchParams.get('page')).toBe('2');
        });
    });

    it('reports missing records and renders empty child collections explicitly', async () => {
        window.location.hash = '#/learner/999';
        const missing = renderScreen({ recordStatus: 404 });
        expect(await screen.findByText("This wasn't found. It may have been removed.")).toBeInTheDocument();
        missing.view.unmount();

        window.location.hash = '#/learner/482';
        renderScreen({ record: { enrolments: [], certificates: [] } });
        expect(await screen.findByText('No enrolments yet.')).toBeInTheDocument();
        expect(screen.getByText('No certificates yet.')).toBeInTheDocument();
    });

    it('re-activates a suspended enrolment through the existing mutation', async () => {
        window.location.hash = '#/learner/482';
        const { requests } = renderScreen();
        await userEvent.click(await screen.findByRole('button', { name: 'Re-activate' }));
        const dialog = await screen.findByRole('dialog', { name: 'Extend access' });
        await userEvent.click(within(dialog).getByRole('radio', { name: 'Never' }));
        await userEvent.click(within(dialog).getByRole('button', { name: 'Re-activate' }));

        await waitFor(() =>
            expect(requests.some((request) => request.path === '/v1/manage/entitlements/905/extend')).toBe(true),
        );
        expect(requests.find((request) => request.path.includes('/extend'))?.body).toEqual({ expires_at: null });
    });

    it('shows a completed enrolment as completed', async () => {
        window.location.hash = '#/learner/482';
        renderScreen({
            record: {
                enrolments: [
                    makeEnrolment({
                        id: 9,
                        status: 'completed',
                        progress_percent: 100,
                        completed_at: '2026-07-12 10:00:00',
                    }),
                ],
            },
        });
        expect(await screen.findByText('Completed 12 Jul')).toBeInTheDocument();
        expect(screen.getByText('100% complete')).toBeInTheDocument();
    });
});
