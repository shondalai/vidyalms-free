import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { EnrolmentRecord } from '@vidyalms/api-sdk';
import { EnrolmentsScreen } from './enrolments';
import {
    jsonResponse,
    makeConfig,
    makeCourse,
    makeEnrolment,
    makeHarness,
    problemResponse,
    userFixtures,
    type RecordedRequest,
} from '../testing/harness';

/*
 * Artboard 2b: source and expiry as columns, suspended as a filter, and the
 * two mutations the screen owns. Extend and Grant access are asserted on
 * the request the SDK sent, because that is the contract.
 */

const ROWS: EnrolmentRecord[] = [
    makeEnrolment({ id: 1 }),
    makeEnrolment({
        id: 2,
        user: { id: 483, name: 'Mira Sato' },
        course: { id: 3, title: 'ARIA in Practice', slug: 'aria-in-practice' },
        entitlement: {
            id: 902,
            user_id: 483,
            course_id: 3,
            source: 'manual',
            source_ref: null,
            status: 'active',
            granted_by: 1,
            starts_at: '2026-03-02 10:00:00',
            expires_at: '2026-09-10 23:59:59',
        },
    }),
    makeEnrolment({
        id: 3,
        user: { id: 484, name: 'Leo Fischer' },
        course: { id: 2, title: 'Inclusive Design Patterns', slug: 'inclusive-design' },
        status: 'suspended',
        entitlement: {
            id: 903,
            user_id: 484,
            course_id: 2,
            source: 'open',
            source_ref: null,
            status: 'active',
            granted_by: null,
            starts_at: '2026-03-02 10:00:00',
            expires_at: null,
        },
    }),
];

interface World {
    rows?: EnrolmentRecord[];
    listStatus?: number;
    listStatuses?: number[];
    total?: number;
}

function renderScreen(world: World = {}) {
    const rows = world.rows ?? ROWS;
    const statuses = [...(world.listStatuses ?? [])];
    const config = makeConfig({ screen: 'enrolments' });
    const harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        if (request.method === 'GET' && url.pathname === '/v1/admin/enrolments') {
            const responseStatus = statuses.shift() ?? world.listStatus;
            if (responseStatus !== undefined && responseStatus !== 200) {
                return problemResponse(responseStatus, 'Refused');
            }
            const status = url.searchParams.get('status');
            const query = (url.searchParams.get('q') ?? '').toLowerCase();
            const filtered = rows.filter(
                (row) =>
                    (status === null || row.status === status) &&
                    (query === '' ||
                        row.user.name?.toLowerCase().includes(query) === true ||
                        row.course.title.toLowerCase().includes(query)),
            );
            const page = Number(url.searchParams.get('page') ?? '1');
            const perPage = Number(url.searchParams.get('per_page') ?? '20');
            const start = (page - 1) * perPage;
            return jsonResponse({
                items: filtered.slice(start, start + perPage),
                total:
                    query === '' && status === null && world.total !== undefined
                        ? world.total
                        : filtered.length,
                page,
                per_page: perPage,
                facets: { active: 1412, expiring: 23, suspended: 61, expiring_within_days: 14 },
            });
        }
        if (request.method === 'GET' && url.pathname === '/v1/admin/users') {
            const query = (url.searchParams.get('q') ?? '').toLowerCase();
            return jsonResponse({
                items: userFixtures.filter((user) => user.name.toLowerCase().includes(query)),
            });
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/courses') {
            return jsonResponse({
                items: [makeCourse({ id: 1, title: 'Foundations of Web Accessibility' })],
                total: 1,
                page: 1,
                per_page: 100,
            });
        }
        if (request.method === 'POST' && /^\/v1\/manage\/entitlements\/\d+\/extend$/.test(url.pathname)) {
            return jsonResponse({ entitlement: ROWS[0]?.entitlement, enrolment: null });
        }
        if (request.method === 'POST' && /^\/v1\/manage\/courses\/\d+\/learners$/.test(url.pathname)) {
            return jsonResponse({
                entitlement: ROWS[0]?.entitlement,
                enrolment: { id: 12, status: 'active' },
            });
        }
        return null;
    }, config);
    const view = render(<EnrolmentsScreen />, { wrapper: harness.wrapper });
    return { ...harness, view };
}

beforeEach(() => {
    window.location.hash = '';
});

describe('EnrolmentsScreen', () => {
    it('renders the learner, course, source and expiry of every row', async () => {
        renderScreen();
        expect(
            await screen.findByRole('button', { name: 'Open the record of Jonas Berg' }),
        ).toBeInTheDocument();
        const table = screen.getByRole('table', { name: 'Enrolments' });
        expect(within(table).getByText('Foundations of Web Accessibility')).toBeInTheDocument();
        expect(within(table).getByText('Shop')).toBeInTheDocument();
        expect(within(table).getByText('Manual')).toBeInTheDocument();
        expect(within(table).getByText('never')).toBeInTheDocument();
    });

    it('shows the contract facet counts on the chips', async () => {
        renderScreen();
        expect(await screen.findByRole('button', { name: 'Active (1412)' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Expiring within 14 days (23)' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Suspended (61)' })).toBeInTheDocument();
    });

    it('keeps summary metrics compact while exposing progress and status as real columns', async () => {
        renderScreen();

        await screen.findByRole('button', { name: 'Active (1412)' });
        const stats = screen.getByRole('list', { name: 'Enrolment summary' });
        expect(within(stats).getByText('1,412')).toBeInTheDocument();
        expect(within(stats).getByText('23')).toBeInTheDocument();
        expect(within(stats).getByText('61')).toBeInTheDocument();
        const table = screen.getByRole('table', { name: 'Enrolments' });
        expect(within(table).getByRole('progressbar', { name: /Jonas Berg/ })).toHaveAttribute(
            'aria-valuenow',
            '41',
        );
        expect(within(table).getByRole('columnheader', { name: /Status/ })).toBeInTheDocument();
    });

    it('renders only one bounded server page for a large enrolment population', async () => {
        const rows = Array.from({ length: 20 }, (_unused, index) =>
            makeEnrolment({
                id: 1000 + index,
                user: { id: 1000 + index, name: `Person ${index + 1}` },
            }),
        );
        renderScreen({ rows, total: 5906 });

        expect(await screen.findByText('5906 total')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: /^Open the record of Person/ })).toHaveLength(20);
        expect(screen.getByText('1 to 20 of 5906')).toBeInTheDocument();
    });

    it('requests the next server page, then resets to page one when a filter changes', async () => {
        const rows = Array.from({ length: 25 }, (_unused, index) =>
            makeEnrolment({
                id: 1000 + index,
                user: { id: 1000 + index, name: `Person ${index + 1}` },
            }),
        );
        const { requests } = renderScreen({ rows });

        await userEvent.click(await screen.findByRole('button', { name: /^Next/ }));
        await waitFor(() => {
            const url = new URL(requests.at(-1)?.path ?? '', 'http://localhost');
            expect(url.searchParams.get('page')).toBe('2');
        });

        await userEvent.click(screen.getByRole('button', { name: 'Expiring within 14 days (23)' }));
        await waitFor(() => {
            const url = new URL(requests.at(-1)?.path ?? '', 'http://localhost');
            expect(url.searchParams.get('page')).toBe('1');
            expect(url.searchParams.get('status')).toBe('active');
            expect(url.searchParams.get('expiring_within_days')).toBe('14');
        });
        expect(window.location.hash).toContain('filter=expiring');
        expect(window.location.hash).not.toContain('page=');
    });

    it('restores a safe bookmarked sort and page from the hash query', async () => {
        window.location.hash = '#/?sort=progress&order=desc&page=2';
        const rows = Array.from({ length: 25 }, (_unused, index) => makeEnrolment({ id: 1000 + index }));
        const { requests } = renderScreen({ rows });

        await waitFor(() => {
            const url = new URL(requests.at(-1)?.path ?? '', 'http://localhost');
            expect(url.searchParams.get('sort')).toBe('progress');
            expect(url.searchParams.get('order')).toBe('desc');
            expect(url.searchParams.get('page')).toBe('2');
        });
    });

    it('asks the server for the expiring window when that chip is chosen', async () => {
        const { requests } = renderScreen();
        await userEvent.click(await screen.findByRole('button', { name: 'Expiring within 14 days (23)' }));
        await waitFor(() => {
            const url = new URL(requests[requests.length - 1]?.path ?? '', 'http://localhost');
            expect(url.searchParams.get('status')).toBe('active');
            expect(url.searchParams.get('expiring_within_days')).toBe('14');
        });
    });

    it('marks a suspended row rather than leaving it to look active', async () => {
        renderScreen();
        expect(await screen.findByText('Suspended')).toBeInTheDocument();
    });

    it('offers no Extend on an open course that never expires', async () => {
        renderScreen();
        expect(await screen.findByText('open course')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: /^Extend access for/ })).toHaveLength(2);
    });

    it('extends an entitlement to a date and says so', async () => {
        const { requests } = renderScreen();
        await userEvent.click(
            await screen.findByRole('button', {
                name: 'Extend access for Jonas Berg on Foundations of Web Accessibility',
            }),
        );
        const dialog = await screen.findByRole('dialog', { name: 'Extend access' });
        await userEvent.clear(within(dialog).getByLabelText('Expiry date'));
        await userEvent.type(within(dialog).getByLabelText('Expiry date'), '2026-12-31');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Save expiry' }));

        await waitFor(() => expect(screen.getByText('Access updated.')).toBeInTheDocument());
        const sent = requests.find((request) => request.path.includes('/extend'));
        expect(sent?.path).toBe('/v1/manage/entitlements/901/extend');
        expect(sent?.body).toEqual({ expires_at: '2026-12-31 23:59:59' });
    });

    it('extends to never when that is the choice', async () => {
        const { requests } = renderScreen();
        await userEvent.click(
            await screen.findByRole('button', {
                name: 'Extend access for Jonas Berg on Foundations of Web Accessibility',
            }),
        );
        const dialog = await screen.findByRole('dialog', { name: 'Extend access' });
        await userEvent.click(within(dialog).getByRole('radio', { name: 'Never' }));
        await userEvent.click(within(dialog).getByRole('button', { name: 'Save expiry' }));

        await waitFor(() => {
            const sent = requests.find((request) => request.path.includes('/extend'));
            expect(sent?.body).toEqual({ expires_at: null });
        });
    });

    it('grants access to a person on a course', async () => {
        const { requests } = renderScreen();
        await userEvent.click(await screen.findByRole('button', { name: 'Grant access' }));
        const dialog = await screen.findByRole('dialog', { name: 'Grant access' });

        await userEvent.type(within(dialog).getByRole('searchbox', { name: 'Learner' }), 'Mira');
        await userEvent.click(await within(dialog).findByRole('button', { name: /Mira Sato/ }));
        await userEvent.click(
            await within(dialog).findByRole('button', { name: 'Foundations of Web Accessibility' }),
        );
        await userEvent.click(within(dialog).getByRole('button', { name: 'Grant access' }));

        await waitFor(() =>
            expect(
                screen.getByText('Mira Sato now has access to Foundations of Web Accessibility.'),
            ).toBeInTheDocument(),
        );
        const sent = requests.find((request) => request.path === '/v1/manage/courses/1/learners');
        expect(sent?.body).toEqual({ user_id: 483, expires_at: null });
    });

    it('refuses to grant access without a person', async () => {
        const { requests } = renderScreen();
        await userEvent.click(await screen.findByRole('button', { name: 'Grant access' }));
        const dialog = await screen.findByRole('dialog', { name: 'Grant access' });
        await userEvent.click(
            await within(dialog).findByRole('button', { name: 'Foundations of Web Accessibility' }),
        );
        await userEvent.click(within(dialog).getByRole('button', { name: 'Grant access' }));

        expect(await within(dialog).findByText('Choose a person.')).toBeInTheDocument();
        expect(requests.some((request) => request.path.endsWith('/learners'))).toBe(false);
    });

    it('opens the learner record through the hash', async () => {
        renderScreen();
        await userEvent.click(await screen.findByRole('button', { name: 'Open the record of Jonas Berg' }));
        expect(window.location.hash).toBe('#/learner/482');
    });

    it('renders the permission state when the account may not manage', async () => {
        renderScreen({ listStatus: 403 });
        expect(await screen.findByText('Managers see courses only.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Go to Courses' })).toHaveAttribute('href', '/admin/courses');
    });

    it('says when nothing matches the filters', async () => {
        renderScreen({ rows: [] });
        expect(await screen.findByText('No enrolments match these filters.')).toBeInTheDocument();
    });
});
