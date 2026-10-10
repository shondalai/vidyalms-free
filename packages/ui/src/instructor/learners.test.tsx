import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { LearnersTab } from './tabs/learners-tab';
import {
    jsonResponse,
    makeEnrolment,
    makeHarness,
    makeConfig,
    makeSuspendedEnrolment,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';

/*
 * Artboard 4a: the roster, where a suspended row keeps its progress visible
 * and its action is Re-activate. Expiry suspends an enrolment; it never
 * deletes what the learner did, and the screen has to show that.
 */

const page = {
    items: [makeEnrolment(), makeSuspendedEnrolment()],
    total: 2,
    page: 1,
    per_page: 25,
};

function handler(overrides: Record<string, Response | (() => Response)> = {}) {
    return (request: RecordedRequest): Response | null => {
        const key = `${request.method} ${request.path.split('?')[0] ?? ''}`;
        const override = overrides[key];
        if (override !== undefined) {
            return typeof override === 'function' ? override() : override.clone();
        }
        if (key === 'GET /v1/manage/courses/1/learners') {
            return jsonResponse(page);
        }
        return null;
    };
}

function renderTab(route: (request: RecordedRequest) => Response | null = handler()) {
    const harness = makeHarness(route, makeConfig({ routes: ['/admin/users'] }));
    render(<LearnersTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });
    return harness;
}

describe('learners tab', () => {
    it('does not offer global account lookup to an ordinary instructor', async () => {
        const harness = makeHarness(handler(), makeConfig({ routes: [] }));
        render(<LearnersTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });
        await screen.findByText('Priya Nair');
        expect(screen.queryByRole('button', { name: 'Enrol learner' })).not.toBeInTheDocument();
        expect(harness.requests.some((request) => request.path.startsWith('/v1/admin/users'))).toBe(false);
    });

    it('lists the roster with progress, last activity, state and source', async () => {
        renderTab();
        expect(await screen.findByText('Priya Nair')).toBeInTheDocument();
        const table = screen.getByRole('table', { name: 'Learners' });
        expect(within(table).getByRole('progressbar', { name: 'Progress of Priya Nair' })).toHaveAttribute(
            'aria-valuenow',
            '83',
        );
        expect(within(table).getByText('Shop')).toBeInTheDocument();
        expect(within(table).getByText('Active')).toBeInTheDocument();
    });

    it('keeps the progress of a suspended learner visible and says it was kept', async () => {
        renderTab();
        expect(await screen.findByText('41% kept')).toBeInTheDocument();
        const table = screen.getByRole('table', { name: 'Learners' });
        expect(within(table).getByText('Suspended')).toBeInTheDocument();
        expect(within(table).getByText(/expired 2026-08-14/)).toBeInTheDocument();
        expect(
            within(table).getByRole('button', { name: 'Re-activate the access of Tom Weiss' }),
        ).toBeInTheDocument();
    });

    it('offers no re-activation on an active row', async () => {
        renderTab();
        await screen.findByText('Priya Nair');
        expect(screen.queryByRole('button', { name: 'Re-activate the access of Priya Nair' })).not.toBeInTheDocument();
    });

    it('sends the search, the state filter and the sort as query parameters', async () => {
        const user = userEvent.setup();
        const harness = renderTab();
        await screen.findByText('Priya Nair');
        await user.type(screen.getByRole('searchbox', { name: 'Search learners' }), 'tom');
        await user.selectOptions(screen.getByRole('combobox', { name: 'State' }), 'suspended');
        await user.click(screen.getByRole('button', { name: /PROGRESS/ }));
        await waitFor(() => {
            const last = harness.requests[harness.requests.length - 1];
            expect(last?.path).toContain('q=tom');
            expect(last?.path).toContain('status=suspended');
            expect(last?.path).toContain('sort=progress');
        });
    });

    it('shows the empty roster state with the action the design names', async () => {
        renderTab(
            handler({
                'GET /v1/manage/courses/1/learners': jsonResponse({ items: [], total: 0, page: 1, per_page: 25 }),
            }),
        );
        expect(await screen.findByText('Nobody is enrolled yet')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Enrol the first learner' })).toBeInTheDocument();
    });

    it('renders the load error and says course data is unaffected', async () => {
        renderTab(handler({ 'GET /v1/manage/courses/1/learners': problemResponse(500) }));
        expect(await screen.findByText("Couldn't load learners.")).toBeInTheDocument();
        expect(screen.getByText('Course data is unaffected.')).toBeInTheDocument();
    });

    it('renders the permission state when the caller may not read the roster', async () => {
        renderTab(handler({ 'GET /v1/manage/courses/1/learners': problemResponse(403) }));
        expect(await screen.findByText("You don't manage this course.")).toBeInTheDocument();
    });
});

describe('re-activation', () => {
    it('extends the entitlement, which is what resumes the enrolment with its progress', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({
                'POST /v1/manage/entitlements/12/extend': jsonResponse({
                    entitlement: makeSuspendedEnrolment().entitlement,
                    enrolment: {
                        id: 4,
                        user_id: 42,
                        course_id: 1,
                        entitlement_id: 12,
                        status: 'active',
                        enrolled_at: '2026-02-14 09:00:00',
                        completed_at: null,
                        progress_percent: 41,
                    },
                }),
            }),
        );
        await screen.findByText('Priya Nair');
        await user.click(screen.getByRole('button', { name: 'Re-activate the access of Tom Weiss' }));
        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('resumes the enrolment with every lesson, score and submission');
        await user.type(within(dialog).getByLabelText('New end date'), '2027-01-31 23:59:00');
        await user.click(within(dialog).getByRole('button', { name: 'Extend access' }));
        await waitFor(() => {
            const write = harness.requests.find(
                (request) => request.path === '/v1/manage/entitlements/12/extend',
            );
            expect(write?.body).toEqual({ expires_at: '2027-01-31 23:59:00' });
        });
    });

    it('refuses a date that is not the contract datetime before it reaches the server', async () => {
        const user = userEvent.setup();
        const harness = renderTab();
        await screen.findByText('Priya Nair');
        await user.click(screen.getByRole('button', { name: 'Re-activate the access of Tom Weiss' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('New end date'), 'next year');
        await user.click(within(dialog).getByRole('button', { name: 'Extend access' }));
        expect(await screen.findByText('Use YYYY-MM-DD HH:MM:SS, or leave empty.')).toBeInTheDocument();
        expect(harness.requests.some((request) => request.method === 'POST')).toBe(false);
    });

    it('shows the conflict when the entitlement was revoked rather than expired', async () => {
        const user = userEvent.setup();
        renderTab(
            handler({
                'POST /v1/manage/entitlements/12/extend': problemResponse(
                    409,
                    'Conflict',
                    'This entitlement was revoked; grant a new one instead.',
                ),
            }),
        );
        await screen.findByText('Priya Nair');
        await user.click(screen.getByRole('button', { name: 'Re-activate the access of Tom Weiss' }));
        const dialog = await screen.findByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Extend access' }));
        expect(await screen.findByText('This entitlement was revoked; grant a new one instead.')).toBeInTheDocument();
    });
});

describe('enrol dialog', () => {
    async function openDialog(user: ReturnType<typeof userEvent.setup>) {
        await screen.findByText('Priya Nair');
        await user.click(screen.getByRole('button', { name: 'Enrol learner' }));
        return screen.findByRole('dialog');
    }

    it('waits for two characters before it searches, as the route requires', async () => {
        const user = userEvent.setup();
        const harness = renderTab();
        const dialog = await openDialog(user);
        await user.type(within(dialog).getByRole('searchbox', { name: 'Find the account' }), 'a');
        expect(harness.requests.some((request) => request.path.startsWith('/v1/admin/users'))).toBe(false);
    });

    it('searches the host accounts and enrols the one that is chosen', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({
                'GET /v1/admin/users': jsonResponse({
                    items: [{ id: 55, name: 'Ana Costa', email: 'ana@example.test' }],
                }),
                'POST /v1/manage/courses/1/learners': jsonResponse(
                    {
                        entitlement: {
                            id: 20,
                            user_id: 55,
                            course_id: 1,
                            source: 'manual',
                            source_ref: null,
                            status: 'active',
                            granted_by: 7,
                            starts_at: null,
                            expires_at: null,
                        },
                        enrolment: {
                            id: 9,
                            user_id: 55,
                            course_id: 1,
                            entitlement_id: 20,
                            status: 'active',
                            enrolled_at: '2026-09-02 10:00:00',
                            completed_at: null,
                            progress_percent: 0,
                        },
                    },
                    201,
                ),
            }),
        );
        const dialog = await openDialog(user);
        await user.type(within(dialog).getByRole('searchbox', { name: 'Find the account' }), 'ana');
        await user.click(await screen.findByRole('button', { name: /Ana Costa/ }));
        await user.click(within(dialog).getByRole('button', { name: 'Enrol learner' }));
        await waitFor(() => {
            const write = harness.requests.find(
                (request) => request.method === 'POST' && request.path === '/v1/manage/courses/1/learners',
            );
            expect(write?.body).toEqual({ user_id: 55, starts_at: null, expires_at: null });
        });
        await waitFor(() => {
            expect(document.querySelector("[data-vy-live='polite']")?.textContent).toBe('Ana Costa is enrolled.');
        });
    });

    it('says a future start provisions access instead of claiming an enrolment', async () => {
        const user = userEvent.setup();
        renderTab(
            handler({
                'GET /v1/admin/users': jsonResponse({
                    items: [{ id: 55, name: 'Ana Costa', email: 'ana@example.test' }],
                }),
                'POST /v1/manage/courses/1/learners': jsonResponse(
                    {
                        entitlement: {
                            id: 20,
                            user_id: 55,
                            course_id: 1,
                            source: 'manual',
                            source_ref: null,
                            status: 'active',
                            granted_by: 7,
                            starts_at: '2026-12-01 09:00:00',
                            expires_at: null,
                        },
                        enrolment: null,
                    },
                    201,
                ),
            }),
        );
        const dialog = await openDialog(user);
        await user.type(within(dialog).getByRole('searchbox', { name: 'Find the account' }), 'ana');
        await user.click(await screen.findByRole('button', { name: /Ana Costa/ }));
        await user.type(within(dialog).getByLabelText('Access starts'), '2026-12-01 09:00:00');
        await user.click(within(dialog).getByRole('button', { name: 'Enrol learner' }));
        await waitFor(() => {
            expect(document.querySelector("[data-vy-live='polite']")?.textContent).toContain(
                'has access from the date you set',
            );
        });
    });

    it('asks for an account before it will submit', async () => {
        const user = userEvent.setup();
        const harness = renderTab();
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Enrol learner' }));
        expect(await screen.findByText('Choose an account first.')).toBeInTheDocument();
        expect(harness.requests.some((request) => request.method === 'POST')).toBe(false);
    });

    it('says so when the search finds nobody', async () => {
        const user = userEvent.setup();
        renderTab(handler({ 'GET /v1/admin/users': jsonResponse({ items: [] }) }));
        const dialog = await openDialog(user);
        await user.type(within(dialog).getByRole('searchbox', { name: 'Find the account' }), 'zzz');
        expect(await screen.findByText('No account matches that.')).toBeInTheDocument();
    });
});
