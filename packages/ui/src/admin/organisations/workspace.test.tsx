import { afterEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { Organisation, OrganisationRolePage } from '@vidyalms/api-sdk';
import { jsonResponse, makeHarness, problemResponse } from '../testing/harness';
import { OrganisationsScreen } from './screen';
import { organisationHash, organisationRoute } from './routes';

const org: Organisation = {
    id: 7,
    name: 'Acme Learning',
    slug: 'acme',
    type: 'organisation',
    state: 'active',
    revision: 4,
    parent_id: null,
    root_id: 7,
    depth: 0,
    contact_email: 'learning@acme.example',
    timezone: 'Asia/Kolkata',
    locale: 'en',
    created_by: 1,
    archived_at: null,
    created_at: '2026-09-05 10:00:00',
    updated_at: '2026-09-05 10:00:00',
    available_types: ['organisation', 'school'],
};
const roles: OrganisationRolePage = {
    items: [
        {
            key: 'org_owner',
            label: 'Owner',
            actions: ['read', 'people', 'learning', 'seats', 'settings', 'events'],
            assignable: true,
        },
    ],
    missing_providers: [],
    access: {
        site_manager: true,
        unit_id: null,
        actions: ['read', 'people', 'learning', 'seats', 'settings', 'events'],
    },
};
const summary = {
    organisation_id: 7,
    active_members: 42,
    released_courses: 8,
    enrolments: 98,
    seat_pools: 1,
    pending_seats: 2,
};
const page = (items: unknown[], total = items.length) => ({ items, total, page: 1, per_page: 20 });
function route(path: string) {
    window.history.replaceState(null, '', `/#${path}`);
}
function read(path: string) {
    if (path.endsWith('/roles')) return jsonResponse(roles);
    if (path.endsWith('/summary')) return jsonResponse(summary);
    if (path.endsWith('/7')) return jsonResponse(org);
    if (path.includes('/events?'))
        return jsonResponse(
            page([
                {
                    id: 1,
                    organisation_id: 7,
                    actor_id: 1,
                    subject_id: 20,
                    event_key: 'membership.joined',
                    organisation_revision: 4,
                    created_at: '2026-09-05 10:00:00',
                },
            ]),
        );
    if (path.includes('/seat-pools?'))
        return jsonResponse(
            page([
                {
                    id: 3,
                    name: 'Leadership seats',
                    course_id: 8,
                    course_title: 'Leadership essentials',
                    state: 'active',
                    balance: { capacity: 100, held: 42, available: 58, reconciled: true, ledger_balance: 42 },
                },
            ]),
        );
    if (path.endsWith('/privacy')) return jsonResponse({ items: [], missing_contributors: [] });
    return jsonResponse(page([]));
}
afterEach(() => window.history.replaceState(null, '', '/'));

describe('modern organization workspace', () => {
    it('switches between cards and tables without losing bounded server filters', async () => {
        route('/?search=acme&state=active');
        const harness = makeHarness(() => jsonResponse(page([org], 81)));
        const user = userEvent.setup();
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        await screen.findByRole('heading', { name: org.name });
        await user.click(screen.getByRole('button', { name: 'Table view' }));
        expect(await screen.findByRole('table', { name: 'Organization directory' })).toBeVisible();
        expect(window.location.hash).toContain('search=acme&state=active&layout=table');
        await user.click(screen.getByRole('button', { name: 'Card view' }));
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(harness.requests.every((request) => request.path.includes('per_page=20'))).toBe(true);
    });
    it('recovers an empty directory page after the result set shrinks', async () => {
        route('/?page=500&search=acme');
        const harness = makeHarness((request) =>
            jsonResponse(page(request.path.includes('page=1&') ? [org] : [], 1)),
        );
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        await screen.findByRole('heading', { name: org.name });
        expect(window.location.hash).toBe('#/?search=acme');
    });
    it('shows real overview counts and pool balances without querying every organization', async () => {
        route('/organisation/7/overview');
        const harness = makeHarness((request) => read(request.path));
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        expect(await screen.findByText('58 available · 100 funded')).toBeVisible();
        expect(screen.getByRole('progressbar', { name: 'Leadership seats' })).toHaveAttribute(
            'aria-valuenow',
            '42',
        );
        expect(screen.getByText('learning@acme.example')).toBeVisible();
        expect(await screen.findByText('Membership joined')).toBeVisible();
        expect(harness.requests.filter((request) => request.path.includes('/seat-pools?'))).toHaveLength(1);
    });
    it('keeps unit managers inside their permitted workspace without organization audit or settings reads', async () => {
        route('/organisation/7/overview');
        const harness = makeHarness((request) =>
            request.path.endsWith('/roles')
                ? jsonResponse({
                      ...roles,
                      access: { site_manager: false, unit_id: 9, actions: ['read', 'people', 'learning'] },
                  })
                : read(request.path),
        );
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        await screen.findByText('42');
        expect(screen.queryByRole('tab', { name: 'Configuration' })).not.toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: 'Activity' })).not.toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: 'Seats & capacity' })).not.toBeInTheDocument();
        expect(harness.requests.some((request) => /events|seat-pools|privacy/.test(request.path))).toBe(
            false,
        );
    });
    it('loads privacy and cleanup only when their configuration section is opened', async () => {
        route('/organisation/7/settings');
        const harness = makeHarness((request) => read(request.path));
        const user = userEvent.setup();
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        await screen.findByLabelText('Organization name');
        expect(harness.requests.some((request) => /privacy|cleanup-jobs/.test(request.path))).toBe(false);
        await user.click(screen.getByRole('button', { name: /Identity & permissions/ }));
        expect(await screen.findByRole('heading', { name: 'Owner' })).toBeVisible();
        await user.click(screen.getByRole('button', { name: /Data & privacy/ }));
        await waitFor(() =>
            expect(harness.requests.some((request) => request.path.endsWith('/privacy'))).toBe(true),
        );
        expect(window.location.hash).toBe('#/organisation/7/settings?state=privacy');
        expect(harness.requests.some((request) => request.path.includes('cleanup-jobs'))).toBe(false);
        await user.click(screen.getByRole('button', { name: /Lifecycle & cleanup/ }));
        await screen.findByRole('button', { name: 'Suspend organization' });
        await waitFor(() =>
            expect(harness.requests.some((request) => request.path.includes('cleanup-jobs'))).toBe(true),
        );
    });
    it('guards configuration navigation, discards locally, and leaves the server record untouched', async () => {
        route('/organisation/7/settings');
        const harness = makeHarness((request) => read(request.path));
        const user = userEvent.setup();
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        await user.type(await screen.findByLabelText('Organization name'), ' changed');
        await user.click(screen.getByRole('button', { name: /Data & privacy/ }));
        expect(screen.getByRole('dialog')).toBeVisible();
        await user.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Organization name')).toHaveValue('Acme Learning changed');
        await user.click(screen.getByRole('button', { name: 'Discard changes' }));
        expect(screen.getByLabelText('Organization name')).toHaveValue(org.name);
        expect(harness.requests.every((request) => request.method === 'GET')).toBe(true);
    });
    it('saves registered organization type with CAS and reuses the request identity after failure', async () => {
        route('/organisation/7/settings');
        let writes = 0;
        let saved = false;
        const harness = makeHarness((request) => {
            if (request.method === 'PATCH') {
                writes++;
                if (writes === 1) return problemResponse(503, 'Try again');
                saved = true;
                return jsonResponse({ ...org, type: 'school', revision: 5 });
            }
            if (saved && request.path.endsWith('/7'))
                return jsonResponse({ ...org, type: 'school', revision: 5 });
            return read(request.path);
        });
        const user = userEvent.setup();
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        await user.selectOptions(
            await screen.findByRole('combobox', { name: 'Organization type' }),
            'school',
        );
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        await screen.findByText('Try again');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        await screen.findByText('All changes saved');
        const requests = harness.requests.filter((request) => request.method === 'PATCH');
        expect(requests[0]?.body).toEqual({ expected_revision: 4, name: org.name, type: 'school' });
        expect(requests[1]?.headers['idempotency-key']).toBe(requests[0]?.headers['idempotency-key']);
    });
    it('reviews onboarding before any write and permits returning to edit the stable address', async () => {
        const harness = makeHarness(() => jsonResponse(page([])));
        const user = userEvent.setup();
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        await user.click(await screen.findByRole('button', { name: 'Create organization' }));
        const dialog = within(screen.getByRole('dialog'));
        await user.type(dialog.getByLabelText('Organization name'), 'Learning & Co');
        await user.click(dialog.getByRole('button', { name: 'Review details' }));
        expect(dialog.getByRole('heading', { name: 'Learning & Co' })).toBeVisible();
        expect(dialog.getByText('learning-co')).toBeVisible();
        expect(harness.requests.every((request) => request.method === 'GET')).toBe(true);
        await user.click(dialog.getByRole('button', { name: 'Back to details' }));
        expect(dialog.getByLabelText('Organization address')).toHaveValue('learning-co');
    });
    it('expands audit evidence in place while retaining server pagination and the original event key', async () => {
        route('/organisation/7/activity');
        const harness = makeHarness((request) => read(request.path));
        const user = userEvent.setup();
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        await user.click(await screen.findByRole('button', { name: 'View event details' }));
        expect(screen.getByLabelText('Event evidence')).toHaveTextContent('membership.joined');
        expect(harness.requests.some((request) => request.path.includes('/events?page=1&per_page=20'))).toBe(
            true,
        );
        await user.click(screen.getByRole('button', { name: 'Hide event details' }));
        expect(screen.queryByLabelText('Event evidence')).not.toBeInTheDocument();
    });
    it('round trips directory display preferences and configuration deep links', () => {
        for (const hash of [
            '/?page=3&search=team&layout=table',
            '/organisation/7/settings?state=permissions',
            '/organisation/7/seats?state=ledger&pool=93',
        ]) {
            const location = organisationRoute(hash);
            expect(organisationRoute(organisationHash(location))).toEqual(location);
        }
    });
});
